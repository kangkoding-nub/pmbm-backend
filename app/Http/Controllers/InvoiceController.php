<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Payment\PaymentFactory;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;


class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        try {
            $invoices = new Invoice();
            $invoices = $request->has('yearId') ? $invoices->whereYearid($request->yearId) : $invoices;
            $invoices = $request->has('institutionId') ? $invoices->whereInstitutionid($request->institutionId) : $invoices;
            $invoices = $request->has('userId') ? $invoices->whereUserid($request->userId) : $invoices;
            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => InvoiceResource::collection($invoices->get())
            ]);
        } catch (Exception $exception) {
            return response([
                'status' => 'error',
                'statusMessage' => $exception->getMessage(),
            ], 500);
        }
    }
    public function store(StoreInvoiceRequest $request)
    {
        try {
            return ($invoice = Invoice::create($request->all()))
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Data Tagihan berhasil dibuat.',
                    'result' => new InvoiceResource($invoice)
                ]) : throw new Exception("Data Tagihan gagal dibuat.");
        } catch (Exception $exception) {
            return response([
                'status' => 'error',
                'statusMessage' => $exception->getMessage(),
            ], 422);
        }
    }
    public function show(Invoice $invoice)
    {
        try {
            return response([
                'status' => 'success',
                'statusMessage' => '',
                'result' => new InvoiceResource($invoice)
            ]);
        } catch (Exception $exception) {
            return response([
                'status' => 'error',
                'statusMessage' => $exception->getMessage(),
            ], 500);
        }
    }
    public function update(UpdateInvoiceRequest $request, Invoice $invoice)
    {
        try {
            // Whitelist via validated() to avoid mass-assignment of fields
            // such as `link`, `createdBy`, or arbitrary input.
            $payload = array_filter(
                $request->validated(),
                fn ($value) => $value !== null && $value !== ''
            );

            return $invoice->update($payload)
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Data Tagihan berhasil diperbarui.',
                    'result' => new InvoiceResource($invoice)
                ]) : throw new Exception("Data Tagihan gagal diperbarui.");
        } catch (Exception $exception) {
            return response([
                'status' => 'error',
                'statusMessage' => $exception->getMessage(),
            ], 422);
        }
    }
    public function destroy(Invoice $invoice)
    {
        try {
            return $invoice->delete()
                ? response([
                    'status' => 'success',
                    'statusMessage' => 'Data Tagihan berhasil dihapus.',
                    'result' => new InvoiceResource($invoice)
                ]) : throw new Exception("Data Tagihan gagal dihapus.");
        } catch (Exception $exception) {
            return response([
                'status' => 'error',
                'statusMessage' => $exception->getMessage(),
            ], 422);
        }
    }

    public function sendWhatsapp(Invoice $invoice)
    {
        try {
            $user = User::with('personal')->find($invoice->userId);
            if (!$user || !$user->phone) {
                return response([
                    'status' => 'error',
                    'statusMessage' => 'Pengguna tidak memiliki nomor telepon yang valid.',
                ], 400);
            }

            if ($invoice->status === 'PAID') {
                 return response([
                    'status' => 'error',
                    'statusMessage' => 'Tagihan ini sudah lunas.',
                ], 400);
            }

            $service = PaymentFactory::create();
            $token = '';

            // Selalu buat transaksi baru setiap resend agar token tidak expired.
            // Token Midtrans hanya berlaku ~24 jam; token lama di DB bisa sudah kedaluwarsa.
            try {
                $adminFee = 3500;
                $totalAmount = (int)$invoice->amount + $adminFee;

                $params = [
                    'transaction_details' => [
                        // order_id unik setiap request agar tidak konflik dengan transaksi sebelumnya
                        'order_id' => $invoice->reference . '-RESEND-' . time(),
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
                            'price' => (int)$invoice->amount,
                            'quantity' => 1,
                            'name' => 'Tagihan PMBM Yayasan Darul Hikmah',
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
                    // Simpan token baru, timpa token lama yang mungkin sudah expired
                    $invoice->update(['link' => $token]);
                }
            } catch (\Exception $e) {
                Log::error('Gagal generate Midtrans link saat resend invoice: ' . $e->getMessage());
            }

            $paymentLink = rtrim($token ? $service->getRedirectUrl($token) : '');
            $institution = Institution::find($invoice->institutionId);
            $institutionName = $institution ? $institution->surname : 'Yayasan Darul Hikmah Menganti';
            $message = "*PMBM YAYASAN DARUL HIKMAH*" . PHP_EOL . PHP_EOL;
            $message .="Assalamaualikum wr.wb" . PHP_EOL;
            $message .= "ini adalah pesan otomatis dari sistem" . PHP_EOL . PHP_EOL;
            $message .= "Halo, {$user->personal->name}." . PHP_EOL;
            $message .= "Ini adalah pesan pengingat tagihan Anda." . PHP_EOL . PHP_EOL;
            $message .= "Jumlah Tagihan: *Rp. " . number_format($invoice->amount, 0, ',', '.') . "*." . PHP_EOL;
            $message .= "Pembayaran paling lambat  01 Juli 2026" . PHP_EOL;

            if ($paymentLink) {
                $message .= "Kunjungi tautan berikut untuk melakukan pembayaran:" . PHP_EOL;
                $message .= $paymentLink . PHP_EOL . PHP_EOL;
            } else {
                $message .= "Silakan masuk ke aplikasi untuk melakukan pembayaran." . PHP_EOL;
            }

            $message .= "Terima kasih." . PHP_EOL . PHP_EOL;
            $message .= "Wassalamualaikum wr wb" . PHP_EOL . PHP_EOL;
            $message .= "-PANITIA PMBM {$institutionName}-" . PHP_EOL;

            SendWhatsAppMessage::dispatch($user->phone, $message);

            return response([
                'status' => 'success',
                'statusMessage' => 'Pesan WhatsApp berhasil dikirim.',
            ]);
        } catch (Exception $exception) {
            return response([
                'status' => 'error',
                'statusMessage' => $exception->getMessage(),
            ], 500);
        }
    }
}
