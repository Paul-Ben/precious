<?php

namespace App\Http\Controllers\Api\V1\Hotel;

use App\Domain\Stays\FolioService;
use App\Http\Controllers\Controller;
use App\Http\Resources\FolioStatementResource;
use App\Models\FolioStatement;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolioController extends Controller
{
    public function __construct(private readonly FolioService $folio) {}

    public function show(string $number): JsonResponse
    {
        return ApiResponse::success(new FolioStatementResource(FolioStatement::query()->where('number', $number)->firstOrFail()));
    }

    public function email(Request $request, string $number): JsonResponse
    {
        $email = $request->validate(['email' => ['nullable', 'email', 'max:190']])['email'] ?? null;
        $statement = FolioStatement::query()->where('number', $number)->firstOrFail();

        return $this->folio->emailStatement($statement, $email)
            ? ApiResponse::success(new FolioStatementResource($statement->refresh()), 'Final bill emailed.')
            : ApiResponse::error('The bill could not be emailed. Check the email address and try again.', 422, 'EMAIL_FAILED');
    }
}
