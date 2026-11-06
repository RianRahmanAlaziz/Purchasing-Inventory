<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Document\Actions\SaveDocument;
use App\Domain\Document\Models\Document;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Document\SaveDocumentRequest;
use App\Http\Resources\Api\V1\DocumentResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $page = Document::query()->with('supplier', 'purchaseOrder')->orderByDesc('id')->paginate($filters['per_page'] ?? 15);

        return ApiResponse::success($page->getCollection()->map(fn ($row) => (new DocumentResource($row))->toArray($request))->all(),
            meta: ['pagination' => ['last_page' => $page->lastPage(), 'current_page' => $page->currentPage(), 'total' => $page->total()]]);
    }

    public function show(Request $request, Document $document): JsonResponse
    {
        return ApiResponse::success((new DocumentResource($document->load('supplier', 'purchaseOrder')))->toArray($request));
    }

    public function store(SaveDocumentRequest $request, SaveDocument $save): JsonResponse
    {
        return ApiResponse::success((new DocumentResource($save->save($request->validated(), (int) $request->user()->id)))->toArray($request), 'Dokumen tersimpan.', 201);
    }

    public function update(SaveDocumentRequest $request, Document $document, SaveDocument $save): JsonResponse
    {
        return ApiResponse::success((new DocumentResource($save->save($request->validated(), (int) $request->user()->id, $document)))->toArray($request));
    }

    public function destroy(Document $document, SaveDocument $save): JsonResponse
    {
        $save->delete($document);

        return ApiResponse::success(null, 'Dokumen dihapus.');
    }

    public function transition(Request $request, Document $document, string $action, SaveDocument $save): JsonResponse
    {
        $next = ['send' => 'sent', 'receive' => 'received', 'verify' => 'verified'][$action];

        return ApiResponse::success((new DocumentResource($save->transition($document, $next, (int) $request->user()->id)))->toArray($request));
    }

    public function download(Document $document): StreamedResponse
    {
        abort_unless($document->file_path && Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->file_name,
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
