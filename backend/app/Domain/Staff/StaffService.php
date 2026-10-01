<?php

namespace App\Domain\Staff;

use App\Domain\Audit\AuditService;
use App\Domain\Identity\UserService;
use App\Enums\EmploymentStatus;
use App\Enums\ShiftStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\DocumentSequence;
use App\Models\Property;
use App\Models\Shift;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Staff records (spec §30, P23). Every staff login has exactly one profile;
 * it is created on demand, so accounts made before M5 or by seeders get one too.
 */
class StaffService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly UserService $users,
    ) {}

    public function ensureProfile(User $user): StaffProfile
    {
        if ($user->relationLoaded('staffProfile') && $user->staffProfile) {
            return $user->staffProfile;
        }

        $profile = StaffProfile::query()->where('user_id', $user->id)->first();

        if (! $profile) {
            $profile = DB::transaction(fn () => StaffProfile::query()->create([
                'property_id' => Property::current()->id,
                'user_id' => $user->id,
                'employee_number' => sprintf('EMP-%04d', DocumentSequence::nextValue('EMP')),
                'start_date' => $user->created_at?->copy()->setTimezone(Property::current()->timezone)->toDateString(),
            ]));
        }

        $user->setRelation('staffProfile', $profile);

        return $profile;
    }

    /** Gives every staff account without a profile its profile and number. */
    public function ensureAllProfiles(): void
    {
        User::query()
            ->where('type', UserType::Staff->value)
            ->whereDoesntHave('staffProfile')
            ->orderBy('created_at')
            ->get() // not chunked: each new profile removes its user from this query's results
            ->each(fn (User $user) => $this->ensureProfile($user));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data, User $actor): StaffProfile
    {
        $profile = $this->ensureProfile($user);
        $leaving = isset($data['employment_status'])
            && $data['employment_status'] === EmploymentStatus::Left->value
            && $profile->employment_status !== EmploymentStatus::Left;

        if ($leaving) {
            $data['end_date'] ??= now(Property::current()->timezone)->toDateString();
        }

        $before = $profile->only(array_keys($data)); // after end_date is added, so it is audited too

        DB::transaction(function () use ($profile, $data, $before, $leaving, $user, $actor) {
            $profile->fill($data)->save();

            $plain = fn (array $values) => array_map(
                fn ($v) => $v instanceof \BackedEnum ? $v->value : ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v),
                $values,
            );
            [$old, $new] = $this->audit->diff($plain($before), $plain($profile->only(array_keys($before))));

            if ($new !== []) {
                $this->audit->record('staff.updated', $user, $old, $new);
            }

            if ($leaving) {
                // P23: someone who has left can no longer sign in, and their future shifts are cancelled.
                if ($user->status === UserStatus::Active) {
                    $this->users->suspend($user, $actor, 'Left the hotel');
                }

                Shift::query()
                    ->where('user_id', $user->id)
                    ->where('status', ShiftStatus::Scheduled->value)
                    ->where('starts_at', '>', now())
                    ->update([
                        'status' => ShiftStatus::Cancelled->value,
                        'cancel_reason' => 'Left the hotel',
                        'cancelled_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        });

        return $profile->refresh()->load('department');
    }

    public function storePhoto(User $user, UploadedFile $file): StaffProfile
    {
        $profile = $this->ensureProfile($user);
        $disk = config('hotel.documents_disk');
        $path = $file->storeAs('staff-photos', $user->id.'-'.Str::random(8).'.'.$file->extension(), $disk);
        $old = [$profile->photo_disk, $profile->photo_path];

        $profile->forceFill(['photo_disk' => $disk, 'photo_path' => $path])->save();
        $this->audit->record('staff.photo_updated', $user);

        if ($old[0] && $old[1]) {
            Storage::disk($old[0])->delete($old[1]);
        }

        return $profile;
    }

    public function deletePhoto(User $user): void
    {
        $profile = $this->ensureProfile($user);

        if ($profile->photo_path) {
            Storage::disk($profile->photo_disk)->delete($profile->photo_path);
            $profile->forceFill(['photo_disk' => null, 'photo_path' => null])->save();
            $this->audit->record('staff.photo_removed', $user);
        }
    }

    public function photo(User $user): StreamedResponse
    {
        $profile = $this->ensureProfile($user);
        abort_unless($profile->photo_path && Storage::disk($profile->photo_disk)->exists($profile->photo_path), 404);

        return Storage::disk($profile->photo_disk)->response($profile->photo_path, null, ['Cache-Control' => 'private, max-age=300']);
    }
}
