<?php

namespace App\Http\Controllers;

use App\Http\Resources\InvoiceResource;
use App\Http\Resources\Master\ItemResource;
use App\Http\Resources\PaymentResource;
use App\Models\Institution;
use App\Models\Institution\Program;
use App\Models\Invoice;
use App\Models\Master\Boarding;
use App\Models\Master\Product;
use App\Models\Payment;
use App\Models\Student\StudentProgram;
use Exception;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function invoice(Request $request)
    {
        try {
            $query = Invoice::with(['personal', 'program', 'payments', 'details'])
                ->when($request->yearId, function ($query) use ($request) {
                    $query->where('yearId', $request->yearId);
                })
                ->when($request->institutionId, function ($query) use ($request) {
                    $query->where('institutionId', $request->institutionId);
                })
                ->when($request->user()->institutionId, function ($query) use ($request) {
                    $query->where('institutionId', $request->user()->institutionId);
                })
                ->when($request->gender, function ($query) use ($request) {
                    $query->whereHas('personal', function ($q) use ($request) {
                        $q->where('gender', $request->gender);
                    });
                })
                ->when($request->programId, function ($query) use ($request) {
                    $query->whereHas('program', function ($q) use ($request) {
                        $q->where('programId', $request->programId);
                    });
                })
                ->when($request->boardingId, function ($query) use ($request) {
                    $query->whereHas('program', function ($q) use ($request) {
                        $q->where('boardingId', $request->boardingId);
                    });
                })->when($request->status, function ($query) use ($request) {
                    $query->where('status', $request->status);
                })
                ->orderBy('created_at', 'desc');

            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => InvoiceResource::collection($query->get())
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 500);
        }
    }

    public function item(Request $request)
    {
        try {
            $query = Product::with(['invoice'])
                ->when($request->yearId, function ($query) use ($request) {
                    $query->where('yearId', $request->yearId);
                })
                ->when($request->institutionId, function ($query) use ($request) {
                    $query->where('institutionId', $request->institutionId);
                })
                ->when($request->user()->institutionId, function ($query) use ($request) {
                    $query->where('institutionId', $request->user()->institutionId);
                });
            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => ItemResource::collection($query->get())
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 500);
        }
    }

    public function payment(Request $request)
    {
        try {
            $query = Payment::with(['personal', 'invoice'])
                ->when($request->yearId, function ($q) use ($request) {
                    $q->whereYearid($request->yearId);
                })
                ->when($request->institutionId, function ($q) use ($request) {
                    $q->where('institutionId', $request->institutionId);
                })
                ->when($request->user()->institutionId, function ($query) use ($request) {
                    $query->where('institutionId', $request->user()->institutionId);
                })
                ->when($request->methodFilter, function ($q) use ($request) {
                    return $q->whereMethod($request->methodFilter);
                })
                ->when($request->filled('dateFrom') && $request->filled('dateTo'), function ($q) use ($request) {
                    $q->whereBetween('transaction_time', [$request->dateFrom . ' 00:00:00', $request->dateTo . ' 23:59:59']);
                })
                ->get();

            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => PaymentResource::collection($query)
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 500);
        }
    }

    public function stats(Request $request)
    {
        try {
            $yearId = $request->input('yearId');
            $institutionId = $request->input('institutionId');

            $invoices = Invoice::query();
            $payments = Payment::query();

            if ($yearId) {
                $invoices->whereYearid($yearId);
                $payments->whereYearid($yearId);
            }

            if ($institutionId) {
                $invoices->whereInstitutionid($institutionId);
                $payments->whereInstitutionid($institutionId);
            }
            $totalStudents = (int) StudentProgram::query()
                ->when($yearId, fn($q) => $q->where('yearId', $yearId))
                ->when($institutionId, fn($q) => $q->where('institutionId', $institutionId))
                ->has('verification')
                ->has('parent')
                ->count();

            $totalInvoicedRemaining = (int)(clone $invoices)->sum('amount');
            $totalPaid = (int)(clone $payments)->sum('amount');

            $totalInvoicedOriginal = $totalPaid + $totalInvoicedRemaining;

            $recentPayments = Payment::with(['personal', 'invoice'])
                ->when($yearId, fn($q) => $q->whereYearid($yearId))
                ->when($institutionId, fn($q) => $q->whereInstitutionid($institutionId))
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
            $request->merge(['type' => 'stats']);

            return response([
                'status' => 'success',
                'result' => [
                    'totalStudents' => $totalStudents,
                    'totalInvoiced' => $totalInvoicedOriginal,
                    'totalPaid' => $totalPaid,
                    'remainingBalance' => $totalInvoicedRemaining,
                    'recentPayments' => PaymentResource::collection($recentPayments)
                ]
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 500);
        }
    }

    public function operatorStats(Request $request)
    {
        try {
            $yearId = $request->input('yearId');
            $institutionId = $request->input('institutionId');

            if (!$institutionId) {
                throw new Exception("Institution ID is required");
            }

            $institution = Institution::find($institutionId);

            // Each metric below shares the same institution + (optional) year
            // scope. Re-build the base query for every metric so we can chain
            // additional `where`/`has` constraints without polluting siblings.
            $scope = fn() => StudentProgram::query()
                ->where('institutionId', $institutionId)
                ->when($yearId, fn($q) => $q->where('yearId', $yearId));

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

            // Pending = total − verified, computed component-wise so the
            // L/P breakdown stays consistent with the verified totals.
            $unverifiedStats = [
                'all'    => $totalStats['all']    - $verifiedStats['all'],
                'male'   => $totalStats['male']   - $verifiedStats['male'],
                'female' => $totalStats['female'] - $verifiedStats['female'],
            ];

            $programs = Program::where('institutionId', $institutionId)
                ->when($yearId, fn($q) => $q->where('yearId', $yearId))
                ->get();

            // Single grouped query (replaces an N+1 count() loop). Joins to
            // student_personals so we can break each program down by gender
            // in one round-trip.
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

            $programBreakdown = $programs->map(function ($program) use ($programRows) {
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
            });

            $recentStudents = StudentProgram::with(['personal', 'verification'])
                ->where('institutionId', $institutionId)
                ->when($yearId, fn($q) => $q->where('yearId', $yearId))
                ->orderBy('created_at', 'desc')
                ->limit(6)
                ->get();

            return response([
                'status' => 'success',
                'result' => [
                    'institution' => $institution,
                    'stats' => [
                        'total'       => $totalStats,
                        'verified'    => $verifiedStats,
                        'unverified'  => $unverifiedStats,
                        'boarding'    => $boardingStats,
                        'nonBoarding' => $nonBoardingStats,
                        'out'         => $outStats,
                        'programs'    => $programBreakdown,
                    ],
                    'recentStudents' => $recentStudents->map(fn($item) => [
                        'name'       => $item->personal?->name,
                        'gender'     => $item->personal?->gender,
                        'verified'   => $item->verification !== null,
                        'created_at' => $item->created_at->toDateTimeString(),
                    ]),
                ],
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Aggregate a StudentProgram query into a gender breakdown.
     *
     * Returns ['all' => N, 'male' => N, 'female' => N]. Joined to
     * student_personals; gender is stored there as int (1=L, 2=P).
     * Counts unknown / null genders as neither male nor female so the
     * sum may legitimately be < `all`.
     */
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
    public function adminStats(Request $request)
    {
        try {
            $yearId = $request->input('yearId');

            $totalInstitutions = Institution::count();

            $studentsQuery = StudentProgram::query()
                ->when($yearId, fn($q) => $q->where('yearId', $yearId));

            $totalStudents = $studentsQuery->count();
            $verifiedStudents = (clone $studentsQuery)->has('verification')->has('parent')->count();
            $unverifiedStudents = $totalStudents - $verifiedStudents;
            $totalStudentsOut = (clone $studentsQuery)->where('status', 2)->count();

            $totalBoarding = (clone $studentsQuery)->whereNotNull('boardingId')->count();
            $totalNonBoarding = (clone $studentsQuery)->whereNull('boardingId')->count();

            $boardingBreakdown = Boarding::get()->map(function ($boarding) use ($yearId) {
                return [
                    'name' => $boarding->name,
                    'count' => StudentProgram::where('boardingId', $boarding->id)
                        ->when($yearId, fn($q) => $q->where('yearId', $yearId))
                        ->count()
                ];
            });

            $institutions = Institution::get()->map(function ($institution) use ($yearId) {
                $query = StudentProgram::where('institutionId', $institution->id)
                    ->when($yearId, fn($q) => $q->where('yearId', $yearId));

                $total = $query->count();
                $out = (clone $query)->where('status', 2)->count();
                $verified = (clone $query)->has('verification')->has('parent')->count();

                $totalPaid = Payment::where('institutionId', $institution->id)
                    ->when($yearId, fn($q) => $q->where('yearId', $yearId))
                    ->sum('amount');

                $remainingBalance = Invoice::where('institutionId', $institution->id)
                    ->when($yearId, fn($q) => $q->where('yearId', $yearId))
                    ->sum('amount');

                return [
                    'id' => $institution->id,
                    'name' => $institution->surname,
                    'totalStudents' => $total,
                    'verified' => $verified,
                    'unverified' => $total - $verified,
                    'out' => $out,
                    'totalPaid' => $totalPaid,
                    'totalUnpaid' => $remainingBalance,
                    'totalInvoiced' => $totalPaid + $remainingBalance,
                ];
            });

            $recentActivity = StudentProgram::with(['personal', 'institution', 'program', 'verification'])
                ->when($yearId, fn($q) => $q->where('yearId', $yearId))
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'name' => $item->personal->name,
                        'institutionName' => $item->institution->surname,
                        'program' => $item->program->alias ?? '-',
                        'created_at' => $item->created_at->toDateTimeString(),
                        'verified' => $item->verification !== null,
                    ];
                });

            $recentPayments = \App\Models\Payment::with(['personal', 'institution'])
                ->when($yearId, fn($q) => $q->where('yearId', $yearId))
                ->latest()
                ->limit(10)
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'amount' => $item->amount,
                        'date' => $item->transaction_time, // Changed from date to transaction_time as per model fillable
                        'studentName' => $item->personal->name ?? '-',
                        'institutionName' => $item->institution->surname ?? '-',
                    ];
                });

            return response([
                'status' => 'success',
                'result' => [
                    'totalInstitutions' => $totalInstitutions,
                    'totalStudents' => $totalStudents,
                    'totalStudentsOut' => $totalStudentsOut,
                    'totalVerified' => $verifiedStudents,
                    'totalUnverified' => $unverifiedStudents,
                    'totalBoarding' => $totalBoarding,
                    'totalNonBoarding' => $totalNonBoarding,
                    'boardingBreakdown' => $boardingBreakdown,
                    'institutions' => $institutions,
                    'recentActivity' => $recentActivity,
                    'recentPayments' => $recentPayments
                ]
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 500);
        }
    }

    public function applicantReport(Request $request)
    {
        try {
            $yearId = $request->input('yearId');
            $institutionId = $request->input('institutionId');
            $programId = $request->input('programId');
            $status = $request->input('status'); // verified|pending
            $boardingId = $request->input('boardingId');

            $query = StudentProgram::with(['personal', 'institution', 'program', 'boarding', 'verification'])
                ->when($yearId, fn($q) => $q->where('yearId', $yearId))
                ->when($institutionId, fn($q) => $q->where('institutionId', $institutionId))
                ->when($programId, fn($q) => $q->where('programId', $programId))
                ->when($boardingId, fn($q) => $q->where('boardingId', $boardingId))
                ->when($status === 'verified', fn($q) => $q->has('verification'))
                ->when($status === 'pending', fn($q) => $q->doesntHave('verification'));

            $students = $query->get()->map(function ($student) {
                return [
                    'id' => $student->id,
                    'name' => $student->personal->name ?? '-',
                    'nisn' => $student->personal->nisn ?? '-',
                    'gender' => $student->personal->gender ?? '-',
                    'institution' => $student->institution->surname ?? '-',
                    'program' => $student->program->name ?? '-',
                    'boarding' => $student->boarding->name ?? 'Non Boarding',
                    'verified' => $student->verification !== null,
                    'created_at' => $student->created_at->toDateTimeString(),
                ];
            });

            return response([
                'status' => 'success',
                'result' => $students
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 500);
        }
    }

    public function discountReport(Request $request)
    {
        try {
            $yearId = $request->input('yearId');
            $institutionId = $request->input('institutionId');

            $query = \App\Models\Invoice\Detail::with(['invoice.personal', 'invoice.institution'])
                ->where('discount', '>', 0)
                ->whereHas('invoice', function ($q) use ($yearId, $institutionId) {
                    if ($yearId) {
                        $q->where('yearId', $yearId);
                    }
                    if ($institutionId) {
                        $q->where('institutionId', $institutionId);
                    }
                });

            $discounts = $query->get()->map(function ($detail) {
                return [
                    'id' => $detail->id,
                    'studentName' => $detail->invoice->personal->name ?? '-',
                    'productName' => $detail->name ?? '-',
                    'amount' => $detail->discount,
                    'description' => 'Potongan untuk ' . ($detail->name ?? 'item'),
                    'created_at' => $detail->created_at->toDateTimeString(),
                ];
            });

            return response([
                'status' => 'success',
                'result' => $discounts
            ]);
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 500);
        }
    }

    public function exportApplicantReport(Request $request)
    {
        $yearId = $request->input('yearId');
        $institutionId = $request->input('institutionId');
        $programId = $request->input('programId');
        $status = $request->input('status');
        $boardingId = $request->input('boardingId');

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\ApplicantReportExport($yearId, $institutionId, $programId, $status, $boardingId),
            'laporan-pendaftar-' . date('Y-m-d-His') . '.xlsx'
        );
    }

    public function exportItemReport(Request $request)
    {
        $yearId = $request->input('yearId');
        $institutionId = $request->input('institutionId');
        if ($request->user()->institutionId) {
            $institutionId = $request->user()->institutionId;
        }

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\ItemReportExport($yearId, $institutionId),
            'laporan-tagihan-item-' . date('Y-m-d-His') . '.xlsx'
        );
    }

    public function exportDiscountReport(Request $request)
    {
        $yearId = $request->input('yearId');
        $institutionId = $request->input('institutionId');

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\DiscountReportExport($yearId, $institutionId),
            'laporan-potongan-' . date('Y-m-d-His') . '.xlsx'
        );
    }

    public function exportInvoiceReport(Request $request)
    {
        $yearId = $request->input('yearId');
        $institutionId = $request->input('institutionId');
        if ($request->user()->institutionId) {
            $institutionId = $request->user()->institutionId;
        }
        $status = $request->input('status');

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\InvoiceReportExport($yearId, $institutionId, $status),
            'laporan-tagihan-' . date('Y-m-d-His') . '.xlsx'
        );
    }

    public function exportPaymentReport(Request $request)
    {
        $yearId = $request->input('yearId');
        $institutionId = $request->input('institutionId');
        if ($request->user()->institutionId) {
            $institutionId = $request->user()->institutionId;
        }

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\PaymentReportExport($yearId, $institutionId),
            'laporan-pembayaran-' . date('Y-m-d-His') . '.xlsx'
        );
    }
}
