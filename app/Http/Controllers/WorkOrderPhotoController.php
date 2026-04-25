<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Models\WorkOrderPhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WorkOrderPhotoController extends Controller
{
    public function store(Request $request, WorkOrder $workOrder)
    {
        $logContext = [
            'work_order_id' => $workOrder->id,
            'user_id'       => $request->user()?->id,
            'ip'            => $request->ip(),
            'user_agent'    => $request->userAgent(),
            'entity_type'   => $request->entity_type,
            'entity_id'     => $request->entity_id,
            'room_id'       => $request->room_id,
            'has_file'      => $request->hasFile('photo'),
            'file_size'     => $request->hasFile('photo') ? $request->file('photo')->getSize() : null,
            'file_mime'     => $request->hasFile('photo') ? $request->file('photo')->getMimeType() : null,
            'file_error'    => $request->hasFile('photo') ? $request->file('photo')->getError() : null,
        ];

        Log::channel('single')->info('[PhotoUpload] Iniciando upload', $logContext);

        try {
            $validated = $request->validate([
                'photo'       => 'required|image|max:8192',
                'entity_type' => 'required|in:adequation,device_event,room_event',
                'entity_id'   => 'nullable|integer',
                'room_id'     => 'nullable|integer',
                'caption'     => 'nullable|string|max:255',
            ]);

            Log::channel('single')->info('[PhotoUpload] Validação OK', $logContext);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel('single')->warning('[PhotoUpload] Falha na validação', array_merge($logContext, [
                'errors' => $e->errors(),
            ]));
            throw $e;
        }

        try {
            $path = $request->file('photo')->store(
                "work-orders/{$workOrder->id}/photos",
                'public'
            );

            Log::channel('single')->info('[PhotoUpload] Arquivo salvo', array_merge($logContext, [
                'path' => $path,
            ]));
        } catch (\Exception $e) {
            Log::channel('single')->error('[PhotoUpload] Falha ao salvar arquivo', array_merge($logContext, [
                'exception' => $e->getMessage(),
            ]));
            throw $e;
        }

        try {
            $photo = WorkOrderPhoto::create([
                'work_order_id' => $workOrder->id,
                'entity_type'   => $request->entity_type,
                'entity_id'     => $request->entity_id,
                'room_id'       => $request->room_id,
                'path'          => $path,
                'caption'       => $request->caption,
            ]);

            Log::channel('single')->info('[PhotoUpload] Registro criado com sucesso', array_merge($logContext, [
                'photo_id' => $photo->id,
                'path'     => $path,
            ]));
        } catch (\Exception $e) {
            Log::channel('single')->error('[PhotoUpload] Falha ao criar registro no banco', array_merge($logContext, [
                'path'      => $path,
                'exception' => $e->getMessage(),
            ]));
            throw $e;
        }

        return response()->json([
            'id'      => $photo->id,
            'url'     => $photo->url,
            'caption' => $photo->caption,
        ]);
    }

    public function destroy(WorkOrder $workOrder, WorkOrderPhoto $photo)
    {
        abort_if($photo->work_order_id !== $workOrder->id, 403);

        Storage::disk('public')->delete($photo->path);
        $photo->delete();

        return response()->json(['success' => true]);
    }
}
