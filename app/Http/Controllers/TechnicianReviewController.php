<?php

namespace App\Http\Controllers;

use App\Actions\Orders\SaveTechnicianReview;
use App\Http\Requests\StoreTechnicianReviewRequest;
use App\Models\ServiceOrder;
use Illuminate\Http\RedirectResponse;

class TechnicianReviewController extends Controller
{
    public function store(StoreTechnicianReviewRequest $request, ServiceOrder $order, SaveTechnicianReview $save): RedirectResponse
    {
        $save->handle($order, $request->user(), $request->validated(), $request->ip());

        return back()->with('success', 'Penilaian teknisi berhasil disimpan. Terima kasih atas evaluasinya.');
    }
}
