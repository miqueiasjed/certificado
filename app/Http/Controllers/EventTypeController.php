<?php

namespace App\Http\Controllers;

use App\Models\EventType;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EventTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $eventTypes = EventType::orderBy('name')->paginate(15);

        return Inertia::render('EventTypes/Index', [
            'eventTypes' => $eventTypes,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('EventTypes/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string|max:7',
            'is_active' => 'boolean',
        ]);

        EventType::create($validated);

        return redirect()->route('event-types.index')
            ->with('success', 'Tipo de evento criado com sucesso!');
    }

    /**
     * Display the specified resource.
     */
    public function show(EventType $eventType)
    {
        return Inertia::render('EventTypes/Show', [
            'eventType' => $eventType,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(EventType $eventType)
    {
        return Inertia::render('EventTypes/Edit', [
            'eventType' => $eventType,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, EventType $eventType)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string|max:7',
            'is_active' => 'boolean',
        ]);

        $eventType->update($validated);

        return redirect()->route('event-types.index')
            ->with('success', 'Tipo de evento atualizado com sucesso!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(EventType $eventType)
    {
        $eventType->delete();

        return redirect()->route('event-types.index')
            ->with('success', 'Tipo de evento excluído com sucesso!');
    }
}
