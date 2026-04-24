<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Models\WorkOrderPhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class WorkOrderPhotoController extends Controller
{
    public function store(Request $request, WorkOrder $workOrder)
    {
        $request->validate([
            'photo'       => 'required|image|max:8192',
            'entity_type' => 'required|in:adequation,device_event,room_event',
            'entity_id'   => 'nullable|integer',
            'room_id'     => 'nullable|integer',
            'caption'     => 'nullable|string|max:255',
        ]);

        $path = $request->file('photo')->store(
            "work-orders/{$workOrder->id}/photos",
            'public'
        );

        $photo = WorkOrderPhoto::create([
            'work_order_id' => $workOrder->id,
            'entity_type'   => $request->entity_type,
            'entity_id'     => $request->entity_id,
            'room_id'       => $request->room_id,
            'path'          => $path,
            'caption'       => $request->caption,
        ]);

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
