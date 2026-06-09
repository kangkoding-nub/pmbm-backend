<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student\StudentPersonal;
use App\Models\User;
use App\Services\LogService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Services\Payment\PaymentFactory;
use App\Models\Payment\Gateway;
use App\Services\PaymentReceiptService;
use App\Services\PdfService;
use App\Services\CertificateService;
use App\Services\TcpdfService;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        try {
            $payments = Payment::with(['personal', 'invoice'])
                ->when($request->yearId, function ($q) use ($request) {
                    return $q->whereYearid($request->yearId);
                })
                ->when($request->institutionId, function ($q) use ($request) {
                    $q->whereInstitutionid($request->institutionId);
                })
                ->when($request->sort, function ($q) use ($request) {
                    $q->orderBy('id', $request->sort);
                })->get();

            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => PaymentResource::collection($payments)
            ]);
        } catch (Exception $exception) {
            return response([
                'status' => 'error',
                'statusMessage' => $exception->getMessage(),
            ], 500);
        }
    }

    public function store(StorePaymentRequest $request)
    {
        try {
            $user = User::find($request->userId);
            $personal = StudentPersonal::whereUserid($request->userId)->first();

            $service = PaymentFactory::create();
            $adminFee = 3500;
            $totalAmount = (int)$request->amount + $adminFee;

            $params = [
                'transaction_details' => [
                    'order_id' => $request->reference . '-' . time(),
                    'gross_amount' => $totalAmount,
                ],
                'customer_details' => [
                    'first_name' => $personal->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                ],
                'item_details' => [
                    [
                        'id' => 'PMBM-2627',
                        'price' => (int)$request->amount,
                        'quantity' => 1,
                        'name' => 'PMBM` Yayasan Darul Hikmah Menganti',
                    ],
                    [
                        'id' => 'ADMIN-FEE',
                        'price' => $adminFee,
                        'quantity' => 1,
                        'name' => 'Biaya Admin',
                    ]
                ],
            ];

            $result = $service->createTransaction($params);

            $paymentLink = $result['token'] ?? '';

            if ($paymentLink) {
                $invoice = Invoice::whereReference($request->reference)->first();
                $invoice->update(['link' => $paymentLink]);

                return response([
                    'status' => 'success',
                    'statusMessage' => 'Transaksi berhasil dibuat.',
                    'result' => [
                        'snap_token' => $paymentLink
                    ]
                ]);
            } else {
                 throw new Exception('Gagal mendapatkan token/url pembayaran.');
            }

        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, Payment $payment)
    {
        try {
            // Roles allowed to mutate payment records:
            //   1 = Administrator, 3 = Bendahara, 6 = Teller.
            // Attacker-controlled fields like `transaction_id`, `amount`,
            // and `userId` are excluded from the update payload below.
            $actor = $request->user('sanctum');
            $allowedRoles = [1, 3, 6];
            if (!$actor || !in_array((int) $actor->role, $allowedRoles, true)) {
                return response([
                    'status' => 'error',
                    'statusMessage' => 'Akses ditolak.'
                ], 403);
            }

            $validated = $request->validate([
                'status'           => 'nullable|integer|in:1,2,3',
                'method'           => 'nullable|integer|in:1,2',
                'transaction_time' => 'nullable|date',
                'note'             => 'nullable|string|max:500',
            ]);

            $payload = array_filter(
                $validated,
                fn ($value) => $value !== null && $value !== ''
            );

            if (empty($payload)) {
                return response([
                    'status' => 'error',
                    'statusMessage' => 'Tidak ada data yang dapat diperbarui.'
                ], 422);
            }

            $payload['updatedBy'] = $actor->id;

            return $payment->update($payload)
            ? response([
                'status' => 'success',
                'statusMessage' => 'Data berhasil diubah.',
                'result' => $payment
            ])
            : throw new Exception('Gagal mengubah data.');
        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 500);
        }
    }

    public function callback(Request $request, $provider = 'midtrans')
    {
        try {
            // Log webhook payload but strip request headers — they may
            // contain Authorization / cookies forwarded by reverse proxies
            // and we don't want secrets ending up in app logs.
            \Log::info("Webhook received from {$provider}", [
                'payload' => $request->all(),
            ]);

            $service = PaymentFactory::createFromProvider($provider);
            $data = $service->handleCallback($request->all());

            // Parse order_id/external_id to get invoice reference.
            // Format expected: {reference}-{SUFFIX?}-{timestamp}.
            $orderId = $data['order_id'] ?? '';

            if (empty($orderId)) {
                return response(['message' => 'Order ID is required'], 400);
            }

            $orderIdParts = explode('-', $orderId);

            if (count($orderIdParts) >= 2) {
                if (count($orderIdParts) == 2) {
                    $invoiceReference = $orderIdParts[0] . '-' . $orderIdParts[1];
                } else {
                    $invoiceReference = $orderIdParts[0] . '-' . $orderIdParts[1];
                }
            } else {
                return response(['message' => 'Invalid order ID format'], 400);
            }

            $transactionStatus = $data['transaction_status'];
            $fraudStatus = $data['fraud_status'] ?? null;

            $isSuccess = false;
            if ($transactionStatus == 'settlement' || ($transactionStatus == 'capture' && $fraudStatus == 'accept')) {
                $isSuccess = true;
            }

            if (!$isSuccess) {
                return response([
                    'status' => 'success',
                    'message' => 'Notification handled (not a settlement)'
                ]);
            }

            // Wrap state mutations in a serialized transaction. The row-level
            // lock on the invoice prevents two concurrent webhook deliveries
            // (replay or duplicate) from both inserting a Payment and double-
            // crediting the invoice.
            DB::transaction(function () use ($invoiceReference, $data) {
                $invoice = Invoice::whereReference($invoiceReference)->lockForUpdate()->first();
                if (!$invoice) {
                    throw new Exception('Invoice not found: ' . $invoiceReference);
                }

                $transactionId = $data['transaction_id'] ?? $data['order_id'];

                // Idempotency guard: if the same transaction has already
                // been recorded, exit silently inside the transaction.
                $existingPayment = Payment::where('transaction_id', $transactionId)
                    ->lockForUpdate()
                    ->first();
                if ($existingPayment) {
                    return;
                }

                $adminFee = 3500;
                $paidAmount = (int) $data['amount'] - $adminFee;
                if ($paidAmount < 0) {
                    $paidAmount = 0;
                }

                Payment::create([
                    'yearId'           => $invoice->yearId,
                    'institutionId'    => $invoice->institutionId,
                    'userId'           => $invoice->userId,
                    'invoiceId'        => $invoice->id,
                    'method'           => 2, // Online
                    'status'           => 2, // Success
                    'transaction_id'   => $transactionId,
                    'transaction_time' => $data['transaction_time'] ?? Carbon::now(),
                    'amount'           => $paidAmount,
                    'createdBy'        => 0, // System
                    'updatedBy'        => 0, // System
                ]);

                $newAmount = $invoice->amount - $paidAmount;
                $invoice->update([
                    'amount' => $newAmount,
                    'status' => ($newAmount <= 0) ? 'PAID' : 'PENDING',
                ]);
            });

            return response([
                'status' => 'success',
                'message' => 'Notification handled'
            ]);

        } catch (Exception $e) {
            \Log::error("Webhook handling failed for {$provider}", [
                'error' => $e->getMessage(),
            ]);
            return response([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function cash(Request $request)
    {
        try {
            $request->validate([
                'userId' => 'required',
                'invoiceId' => 'required',
                'amount' => 'required|integer|min:1',
            ]);

            $invoice = Invoice::find($request->invoiceId);

            if ($request->amount > $invoice->amount) {
                return response([
                    'status' => 'error',
                    'statusMessage' => 'Jumlah pembayaran melebihi total tagihan.'
                ], 422);
            }

            $payment = Payment::create([
                'yearId' => $invoice->yearId,
                'institutionId' => $invoice->institutionId,
                'userId' => $request->userId,
                'invoiceId' => $request->invoiceId,
                'method' => 1,
                'status' => 2,
                'transaction_id' => 'CASH-' . time(),
                'transaction_time' => Carbon::now()->toDateTimeString(),
                'amount' => $request->amount,
                'createdBy' => auth()->id(),
                'updatedBy' => auth()->id(),
            ]);

            $newAmount = $invoice->amount - $request->amount;
            $invoice->update([
                'amount' => $newAmount,
                'status' => ($newAmount <= 0) ? 'PAID' : 'PENDING'
            ]);

            return response([
                'status' => 'success',
                'statusMessage' => 'Pembayaran cash berhasil dicatat.',
                'result' => $payment
            ]);

        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate and download payment receipt PDF
     */
    public function generateReceipt(Request $request, $id)
    {
        $qrCodePath = null; // Track QR code path for cleanup

        try {
            \Log::info('Generate receipt requested', ['payment_id' => $id]);

            $payment = Payment::with(['user', 'personal', 'institution', 'invoice'])->findOrFail($id);

            // Check if payment is successful and invoice is fully paid
            if ($payment->status != 2) {
                \Log::warning('Payment not successful', ['payment_id' => $id, 'status' => $payment->status]);
                return response()->json([
                    'status' => 'error',
                    'statusMessage' => 'Bukti pembayaran hanya dapat dicetak untuk pembayaran yang berhasil.'
                ], 400);
            }

            $user = auth()->user();
            $isTreasurer = $user && $user->role == 3;

            if (!$isTreasurer && $payment->invoice->status !== 'PAID') {
                return response()->json([
                    'status' => 'error',
                    'statusMessage' => 'Bukti pembayaran hanya dapat dicetak jika tagihan sudah lunas.'
                ], 400);
            }

            $receiptService = new PaymentReceiptService();

            // Generate receipt if not exists
            if (!$payment->receipt_number) {
                \Log::info('Generating new receipt number', ['payment_id' => $id]);
                $payment = $receiptService->generateReceipt($payment, auth()->user());
            }

            // Get receipt data
            \Log::info('Getting receipt data', ['payment_id' => $id]);
            $data = $receiptService->getReceiptData($payment, $request->query('frontend_url'));

            // Store QR code path for cleanup
            $qrCodePath = $data['qr_code_path'] ?? null;

            // Generate signed PDF
            $signedPath = $receiptService->generateSignedPdfFile($data);
            $filename = 'bukti-pembayaran-' . ($payment->receipt_number ?? $id) . '.pdf';

            // Return the signed PDF as a response
            $response = response()->download($signedPath, $filename)->deleteFileAfterSend(true);

            // Cleanup: Delete temporary QR code
            if ($qrCodePath && file_exists($qrCodePath)) {
                @unlink($qrCodePath);
            }

            return $response;

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // Cleanup QR code on error
            if ($qrCodePath && file_exists($qrCodePath)) {
                @unlink($qrCodePath);
            }

            \Log::error('Payment not found', ['payment_id' => $id]);
            return response()->json([
                'status' => 'error',
                'statusMessage' => 'Pembayaran tidak ditemukan.'
            ], 404);
        } catch (\Exception $e) {
            // Cleanup QR code on error
            if ($qrCodePath && file_exists($qrCodePath)) {
                @unlink($qrCodePath);
            }

            \Log::error('Generate receipt error', [
                'payment_id' => $id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return response()->json([
                'status' => 'error',
                'statusMessage' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download existing receipt
     */
    public function downloadReceipt(Request $request, $id)
    {
        try {
            $payment = Payment::with(['user', 'personal', 'institution', 'invoice'])->findOrFail($id);

            if (!$payment->receipt_number) {
                return response([
                    'status' => 'error',
                    'statusMessage' => 'Bukti pembayaran belum di-generate.'
                ], 404);
            }

            $user = auth()->user();
            $isTreasurer = $user && $user->role == 3;

            if (!$isTreasurer && $payment->invoice->status !== 'PAID') {
                return response()->json([
                    'status' => 'error',
                    'statusMessage' => 'Bukti pembayaran hanya dapat diunduh jika tagihan sudah lunas.'
                ], 400);
            }

            $receiptService = new PaymentReceiptService();
            $pdfService = new PdfService();

            $data = $receiptService->getReceiptData($payment, $request->query('frontend_url'));
            $html = $receiptService->generatePDFHtml($data);

            // Generate PDF and return for download
            $filename = 'receipt-' . $payment->receipt_number . '.pdf';

            return $pdfService->generateProtectedPdf($html, $filename);

        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download all receipts for logged in student
     */
    public function downloadAllReceipts(Request $request)
    {
        try {
            $userId = auth()->id();
            $payments = Payment::with(['user', 'personal', 'institution', 'invoice'])
                ->where('userId', $userId)
                ->where('status', 2)
                ->get();

            if ($payments->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'statusMessage' => 'Belum ada pembayaran yang berhasil.'
                ], 404);
            }

            $invoice = $payments->first()->invoice;

            if ($invoice && $invoice->status !== 'PAID') {
                return response()->json([
                    'status' => 'error',
                    'statusMessage' => 'Bukti pembayaran hanya dapat diunduh jika tagihan sudah lunas.'
                ], 400);
            }

            $receiptService = new PaymentReceiptService();
            $pdfService = new PdfService();

            foreach ($payments as $payment) {
                if (!$payment->receipt_number) {
                    $receiptService->generateReceipt($payment, auth()->user());
                }
            }

            $payments = $payments->fresh(['user', 'personal', 'institution', 'invoice']);

            $paymentsData = [];
            foreach ($payments as $payment) {
                $data = $receiptService->getReceiptData($payment, $request->query('frontend_url'));
                $paymentsData[] = $data;
            }

            $html = $receiptService->generateMultiplePDFHtml($paymentsData);

            $filename = 'semua-kuitansi-pembayaran.pdf';

            return $pdfService->generateProtectedPdf($html, $filename);

        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify receipt by token (public endpoint)
     */
    public function verifyReceipt($token)
    {
        try {
            $payment = Payment::with(['user', 'personal', 'institution', 'invoice'])
                ->where('receipt_token', $token)
                ->first();

            if (!$payment) {
                return response([
                    'status' => 'error',
                    'statusMessage' => 'Bukti pembayaran tidak ditemukan atau tidak valid.'
                ], 404);
            }

            return response([
                'status' => 'success',
                'statusMessage' => 'Bukti pembayaran valid.',
                'result' => [
                    'valid' => true,
                    'receipt_number' => $payment->receipt_number,
                    'payment' => [
                        'amount' => $payment->amount,
                        'transaction_id' => $payment->transaction_id,
                        'transaction_time' => $payment->transaction_time,
                        'method' => $payment->method == 1 ? 'Tunai' : 'Online (Midtrans)',
                    ],
                    'student' => [
                        'name' => $payment->personal->name,
                    ],
                    'institution' => [
                        'name' => $payment->institution->name,
                        'logo' => $payment->institution->logo,
                    ],
                    'invoice' => [
                        'reference' => $payment->invoice->reference,
                    ],
                    'generated_at' => $payment->receipt_generated_at,
                ]
            ]);

        } catch (Exception $e) {
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 500);
        }
    }

    public function sendWhatsapp(Payment $payment)
    {
        try {
            $payment->load(['institution']);
            $user = User::with('personal')->find($payment->userId);

            if (!$user || !$user->phone) {
                return response([
                    'status' => 'error',
                    'statusMessage' => 'Pengguna tidak memiliki nomor telepon yang valid.',
                ], 400);
            }

            // --- Blok 1: Kirim bukti pembayaran ---
            $paymentReceiptService = new PaymentReceiptService();
            $payment = $paymentReceiptService->generateReceipt($payment, $user);
            $receiptData = $paymentReceiptService->getReceiptData($payment);
            $receiptPath = $paymentReceiptService->generateSignedPdfFile($receiptData);
            $institution = Institution::find($payment->institutionId);
            $institutionName = $institution ? $institution->surname : 'Yayasan Darul Hikmah Menganti';
            $message = "*PMBM YAYASAN DARUL HIKMAH*" . PHP_EOL . PHP_EOL;
            $message .= "Assalamualaikum wr wb." . PHP_EOL;
            $message .= "ini adalah pesan otomatis dari sistem" . PHP_EOL . PHP_EOL;
            $message .= "Halo, {$user->personal->name}." . PHP_EOL;
            $message .= "Pembayaran Anda sebesar *Rp. " . number_format($payment->amount) . "* telah kami terima." . PHP_EOL . PHP_EOL;
            $message .= "Metode: " . ($payment->method == 1 ? 'Cash / Tunai' : 'Online Transfer') . PHP_EOL;
            $message .= "ID Transaksi: {$payment->transaction_id}" . PHP_EOL;
            $message .= "Waktu: {$payment->transaction_time}" . PHP_EOL . PHP_EOL;
            $message .= "Terlampir adalah bukti pembayaran Anda." . PHP_EOL . PHP_EOL;
            $message .= "Terima kasih telah melakukan pembayaran. Silakan cek status pendaftaran Anda di aplikasi." . PHP_EOL . PHP_EOL;
            $message .= "Wassalamualaikum wr wb" . PHP_EOL . PHP_EOL;
            $message .= "-Panitia PMBM {$institutionName}-" . PHP_EOL;

            SendWhatsAppMessage::dispatch($user->phone, $message, null, $message, $receiptPath);
            LogService::transaction("Pembayaran diterima: Rp. " . number_format($payment->amount) . " dari {$user->personal->name}", [
                'paymentId' => $payment->id,
                'transaction_id' => $payment->transaction_id,
            ]);

            // --- Blok 2: Cek sisa tagihan & kirim reminder + link baru jika belum lunas ---
            $invoice = Invoice::find($payment->invoiceId);
            if ($invoice && $invoice->amount > 0 && $invoice->status !== 'PAID') {
                $sisaTagihan = $invoice->amount;
                $paymentLink = '';

                try {
                    $service = PaymentFactory::create();
                    $adminFee = 3500;
                    $totalAmount = (int)$sisaTagihan + $adminFee;

                    $params = [
                        'transaction_details' => [
                            'order_id' => $invoice->reference . '-SISA-' . time(),
                            'gross_amount' => $totalAmount,
                        ],
                        'customer_details' => [
                            'first_name' => $user->personal->name ?? '',
                            'email' => $user->email,
                            'phone' => $user->phone,
                        ],
                        'item_details' => [
                            [
                                'id' => 'PMBM-' . $invoice->yearId . '-' . $invoice->institutionId,
                                'price' => (int)$sisaTagihan,
                                'quantity' => 1,
                                'name' => 'Sisa Tagihan PMBM Yayasan Darul Hikmah',
                            ],
                            [
                                'id' => 'ADMIN-FEE',
                                'price' => $adminFee,
                                'quantity' => 1,
                                'name' => 'Biaya Admin',
                            ]
                        ],
                    ];

                    $result = $service->createTransaction($params);
                    $token = $result['token'] ?? '';

                    if (!empty($token)) {
                        Invoice::where('id', $invoice->id)->update(['link' => $token]);
                        // Bangun URL redirect dari token (bukan redirect_url yang tidak ada di Snap API)
                        $paymentLink = $service->getRedirectUrl($token);
                    }
                } catch (Exception $e) {
                    LogService::error('Gagal generate Midtrans link untuk sisa tagihan: ' . $e->getMessage(), [
                        'invoiceId' => $invoice->id,
                        'error' => $e->getMessage()
                    ]);
                }

                $reminder = "*PMBM YAYASAN DARUL HIKMAH*" . PHP_EOL . PHP_EOL;
                $reminder .= "Assalamualaikum wr wb." . PHP_EOL;
                $reminder .= "ini adalah pesan otomatis dari sistem" . PHP_EOL . PHP_EOL;
                $reminder .= "Pembayaran Anda telah kami catat, namun tagihan Anda belum lunas." . PHP_EOL;
                $reminder .= "Sisa tagihan yang harus dibayarkan adalah sebesar *Rp. " . number_format($sisaTagihan, 0, ',', '.') . "*." . PHP_EOL . PHP_EOL;

                if ($paymentLink) {
                    $reminder .= "Silakan kunjungi tautan berikut untuk melakukan pembayaran sisa tagihan:" . PHP_EOL;
                    $reminder .= $paymentLink . PHP_EOL . PHP_EOL;
                } else {
                    $reminder .= "Silakan masuk ke aplikasi untuk melanjutkan pembayaran sisa tagihan." . PHP_EOL . PHP_EOL;
                }
                $reminder .= "Terima kasih." . PHP_EOL . PHP_EOL;
                $reminder .= "Wassalamualaikum wr wb" . PHP_EOL . PHP_EOL;
                $reminder .= "-Panitia PMBM {$institutionName}-" . PHP_EOL;

                SendWhatsAppMessage::dispatch($user->phone, $reminder);
            }

            return response([
                'status' => 'success',
                'statusMessage' => 'Notifikasi telah dikirim',
            ]);

        } catch (Exception $e) {
            LogService::error('PaymentController::sendWhatsapp Error: ' . $e->getMessage(), [
                'paymentId' => $payment->id,
                'error' => $e->getMessage()
            ]);
            return response([
                'status' => 'error',
                'statusMessage' => $e->getMessage()
            ], 500);
        }
    }
}

