<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Models\Institution\Program;
use App\Models\Student\StudentProgram;
use Exception;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function operator(Request $request)
    {
        try {
            $yearId        = $request->input('yearId');
            $institutionId = $request->input('institutionId');

            if (!$institutionId) {
                throw new Exception("Institution ID is required");
            }

            $institution = Institution::find($institutionId);
            $scope       = fn() => StudentProgram::query()
                ->where('institutionId', $institutionId)
                ->when($yearId, fn($q) => $q->where('yearId', $yearId));

            $stats                 = $this->buildStats($scope);
            $stats['programs']     = $this->buildProgramBreakdown($institutionId, $yearId);
            $recentStudents        = $this->buildRecentStudents($institutionId, $yearId);

            return response([
                'status' => 'success',
                'result' => [
                    'institution'    => $institution,
                    'stats'          => $stats,
                    'recentStudents' => $recentStudents,
                ],
            ]);
        } catch (Exception $e) {
            return response([
                'status'        => 'error',
                'statusMessage' => $e->getMessage(),
            ], 500);
        }
    }

    private function buildStats(\Closure $scope): array
    {
        $totalStats       = $this->genderBreakdown($scope());
        $verifiedStats    = $this->genderBreakdown(
            $scope()->has('verification')->has('parent')
        );
        $boardingStats    = $this->genderBreakdown(
            $scope()->where('boardingId', '!=', 1)
        );
        $nonBoardingStats = $this->genderBreakdown(
            $scope()->where('boardingId', 1)
        );
        $outStats         = $this->genderBreakdown(
            $scope()->where('status', 2)
        );

        $unverifiedStats = [
            'all'    => $totalStats['all']    - $verifiedStats['all'],
            'male'   => $totalStats['male']   - $verifiedStats['male'],
            'female' => $totalStats['female'] - $verifiedStats['female'],
        ];

        return [
            'total'       => $totalStats,
            'verified'    => $verifiedStats,
            'unverified'  => $unverifiedStats,
            'boarding'    => $boardingStats,
            'nonBoarding' => $nonBoardingStats,
            'out'         => $outStats,
            'programs'    => [], // populated by caller
        ];
    }

    private function buildProgramBreakdown(int $institutionId, int $yearId): array
    {
        $programs = Program::where('institutionId', $institutionId)
            ->when($yearId, fn($q) => $q->where('yearId', $yearId))
            ->get();

        $programRows = StudentProgram::query()
            ->where('student_programs.institutionId', $institutionId)
            ->when($yearId, fn($q) => $q->where('student_programs.yearId', $yearId))
            ->join(
                'student_personals',
                'student_personals.userId',
                '=',
                'student_programs.userId'
            )
            ->selectRaw(
                'student_programs.programId as program_id,
                 COUNT(*) as all_count,
                 SUM(CASE WHEN student_personals.gender = 1 THEN 1 ELSE 0 END) as male_count,
                 SUM(CASE WHEN student_personals.gender = 2 THEN 1 ELSE 0 END) as female_count'
            )
            ->groupBy('student_programs.programId')
            ->get()
            ->keyBy('program_id');

        return $programs->map(function ($program) use ($programRows) {
            $row = $programRows->get($program->id);
            return [
                'name'  => $program->name,
                'alias' => $program->alias,
                'total' => [
                    'all'    => $row ? (int) $row->all_count    : 0,
                    'male'   => $row ? (int) $row->male_count   : 0,
                    'female' => $row ? (int) $row->female_count : 0,
                ],
            ];
        })->values()->all();
    }

    private function buildRecentStudents($institutionId, $yearId): array
    {
        $recentStudents = StudentProgram::with(['personal', 'verification'])
            ->where('institutionId', $institutionId)
            ->when($yearId, fn($q) => $q->where('yearId', $yearId))
            ->orderBy('created_at', 'desc')
            ->limit(6)
            ->get();

        return $recentStudents->map(fn($item) => [
            'name'       => $item->personal?->name,
            'gender'     => $item->personal?->gender,
            'verified'   => $item->verification !== null,
            'created_at' => $item->created_at->toDateTimeString(),
        ])->all();
    }

    private function genderBreakdown($query): array
    {
        $row = $query
            ->join(
                'student_personals',
                'student_personals.userId',
                '=',
                'student_programs.userId'
            )
            ->selectRaw(
                'COUNT(*) as all_count,
                 SUM(CASE WHEN student_personals.gender = 1 THEN 1 ELSE 0 END) as male_count,
                 SUM(CASE WHEN student_personals.gender = 2 THEN 1 ELSE 0 END) as female_count'
            )
            ->first();

        return [
            'all'    => (int) ($row->all_count    ?? 0),
            'male'   => (int) ($row->male_count   ?? 0),
            'female' => (int) ($row->female_count ?? 0),
        ];
    }
}
