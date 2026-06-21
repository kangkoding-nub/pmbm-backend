<?php

namespace App\Http\Controllers;

use App\Http\Resources\Master\YearResource;
use App\Models\Institution;
use App\Models\Institution\Activity;
use App\Models\Institution\Period;
use App\Models\Master\Rule;
use App\Models\Master\Year;
use App\Models\Student\StudentProgram;
use Exception;

class PublicController extends Controller
{
    public function landing ()
    {
        try {
            $year = Year::whereActive(1)->first();
            $institutions = Institution::all()->map(function ($institution) use ($year) {
                $count = StudentProgram::where('institutionId', $institution->id)
                    ->where('yearId', $year->id)
                    ->count();
                $period = Period::where('institutionId', $institution->id)
                    ->whereDate('start', '<=', now())
                    ->whereDate('end', '>=', now())
                    ->first();
                return [
                    'id' => $institution->id,
                    'name' => $institution->name,
                    'surname' => $institution->surname,
                    'logo' => $institution->logo,
                    'registrants_count' => $count,
                    'period' => $period
                ];
            });

            // Latest Registrants
            $registrants = StudentProgram::where('yearId', $year->id)
                ->with(['personal', 'parent', 'address', 'institution'])
                ->latest()
                ->limit(10)
                ->get()
                ->map(function($student) {
                    return [
                        'id' => $student->id,
                        'name' => $student->personal?->name,
                        'guardian' => $student->parent?->fatherName ?? $student->parent?->motherName ?? $student->parent?->guardianName,
                        'address' => $student->address?->street,
                        'institution' => $student->institution?->surname
                    ];
                });

            // Brochures
            $brochures = Activity::where('yearId', $year->id)
                ->whereNotNull('brochure')
                ->with('institution')
                ->get()
                ->map(function($activity) {
                    return [
                        'id' => $activity->id,
                        'brochure' => $activity->brochure,
                        'institution' => [
                             'name' => $activity->institution?->name,
                             'surname' => $activity->institution?->surname,
                             'logo' => $activity->institution?->logo
                        ]
                    ];
                });

            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => [
                    'year' => $year->name,
                    'institutions' => $institutions,
                    'registrants' => $registrants,
                    'brochures' => $brochures
                ]
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 500);
        }
    }

    public function year ()
    {
        try {
            $year = Year::whereActive(1)->first();
            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => new YearResource($year),
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 500);
        }
    }
    public function rules ()
    {
        try {
            $general = Rule::whereNull('institutionId')->get();
            $institutions = Institution::with(['rules' => function($q) {
                $q->latest();
            }])->get()->map(function($inst) {
                return [
                    'id' => $inst->id,
                    'name' => $inst->name,
                    'surname' => $inst->surname,
                    'logo' => $inst->logo,
                    'rules' => $inst->rules
                ];
            });

            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => [
                    'general' => $general,
                    'institutions' => $institutions
                ]
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 500);
        }
    }
    public function schedule()
    {
        try {
            $year = Year::whereActive(1)->first();
            $periods = Period::whereYearid($year->id)
                ->with('institution')
                ->orderBy('start')
                ->get()
                ->map(function ($period) {
                    return [
                        'id' => $period->id,
                        'name' => $period->name,
                        'description' => $period->description,
                        'start' => $period->start,
                        'end' => $period->end,
                        'institution' => [
                            'name' => $period->institution?->name,
                            'surname' => $period->institution?->surname,
                            'logo' => $period->institution?->logo,
                        ]
                    ];
                });

            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => [
                    'year' => $year->name,
                    'schedules' => $periods
                ]
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 500);
        }
    }
}
