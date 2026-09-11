<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function index()
    {
        return Agent::withCount('accounts')->orderBy('agent_name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'agent_name' => ['required', 'string', 'max:255'],
            'agent_code' => ['required', 'string', 'max:50', 'unique:agents,agent_code'],
            'logo_url' => ['nullable', 'url', 'max:2048'],
            'status' => ['boolean'],
        ]);

        $agent = Agent::create($data);

        return response()->json($agent, 201);
    }

    public function show(Agent $agent)
    {
        return $agent->load('accounts');
    }

    public function update(Request $request, Agent $agent)
    {
        $data = $request->validate([
            'agent_name' => ['sometimes', 'required', 'string', 'max:255'],
            'agent_code' => ['sometimes', 'required', 'string', 'max:50', 'unique:agents,agent_code,' . $agent->id],
            'logo_url' => ['nullable', 'url', 'max:2048'],
            'status' => ['boolean'],
        ]);

        $agent->update($data);

        return $agent;
    }

    public function destroy(Agent $agent)
    {
        $agent->delete();

        return response()->json(['message' => 'ลบ Agent เรียบร้อย']);
    }
}
