<?php

namespace App\Http\Controllers;

use App\Models\ReportSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class ReportScheduleController extends Controller
{
    public function index()
    {
        return ReportSchedule::with(['branch:id,name,code', 'agentAccount:id,username_acc'])->orderBy('name')->get();
    }

    private function rules(?ReportSchedule $schedule = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'frequency' => ['required', 'in:daily,weekly,monthly'],
            'send_time' => ['required', 'date_format:H:i'],
            'day_of_week' => ['required_if:frequency,weekly', 'nullable', 'integer', 'between:0,6'],
            'day_of_month' => ['required_if:frequency,monthly', 'nullable', 'integer', 'between:1,31'],
            'report_range' => ['required', 'in:daily,weekly,monthly,yearly'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'carrier' => ['nullable', 'in:UPS,DHL'],
            'agent_account_id' => ['nullable', 'exists:agent_accounts,id'],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*' => ['required', 'email'],
            'is_active' => ['boolean'],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $data['report_type'] = 'manifest';

        return response()->json(ReportSchedule::create($data), 201);
    }

    public function update(Request $request, ReportSchedule $reportSchedule)
    {
        $data = $request->validate($this->rules($reportSchedule));
        $reportSchedule->update($data);

        return $reportSchedule;
    }

    public function destroy(ReportSchedule $reportSchedule)
    {
        $reportSchedule->delete();

        return response()->json(['message' => 'ลบตารางส่งรายงานเรียบร้อย']);
    }

    /**
     * Sends this schedule's report right now, bypassing the frequency/day/time gate — lets
     * staff verify recipients/format immediately after configuring it, same as
     * TrackingSyncController::runNow.
     */
    public function sendNow(ReportSchedule $reportSchedule)
    {
        Artisan::call('reports:send-scheduled', ['--force' => true, '--only' => $reportSchedule->id]);

        return response()->json(['message' => 'ส่งรายงานเรียบร้อย', 'output' => Artisan::output()]);
    }
}
