<?php

namespace App\Http\Controllers;

use App\Models\DocumentVerification;
use App\Models\Letter;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LetterController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Letter::with('creator:id,name')
            ->latest('letter_date')
            ->latest('id');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('q')) {
            $search = $request->q;
            $query->where(fn ($q) => $q->where('subject', 'like', "%{$search}%")
                ->orWhere('number', 'like', "%{$search}%")
                ->orWhere('from_to', 'like', "%{$search}%")
            );
        }

        $letters = $query->paginate(20)->withQueryString();

        return Inertia::render('Persuratan/Index', [
            'letters' => $letters,
            'filters' => $request->only(['type', 'category', 'q']),
            'stats' => [
                'masuk' => Letter::where('type', 'masuk')->count(),
                'keluar' => Letter::where('type', 'keluar')->count(),
                'bulan' => Letter::whereMonth('letter_date', now()->month)
                    ->whereYear('letter_date', now()->year)->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $type = $request->get('type', 'keluar');
        $draftNumber = '';

        if ($type === 'keluar') {
            // Preview nomor otomatis
            $lembaga = $this->getLembagaCode();
            $draftNumber = Letter::generateOutNumber('umum', $lembaga);
        }

        return Inertia::render('Persuratan/Form', [
            'type' => $type,
            'draftNumber' => $draftNumber,
            'categories' => $this->categories(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => 'required|in:masuk,keluar',
            'number' => 'nullable|string|max:100',
            'subject' => 'required|string|max:500',
            'category' => 'required|string',
            'letter_date' => 'required|date',
            'received_date' => 'nullable|date',
            'from_to' => 'required|string|max:255',
            'description' => 'nullable|string',
            'disposisi' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
        ]);

        // Auto-generate nomor surat keluar
        if ($data['type'] === 'keluar' && empty($data['number'])) {
            $data['number'] = Letter::generateOutNumber($data['category'], $this->getLembagaCode());
        }

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('persuratan', 'public');
        }

        $letter = Letter::create([
            ...$data,
            'attachment_path' => $attachmentPath ? '/storage/'.$attachmentPath : null,
            'created_by' => auth()->id(),
        ]);

        // Token verifikasi QR (stabil per surat)
        DocumentVerification::issue(
            DocumentVerification::TYPE_SURAT_KELUAR,
            $letter,
            [
                'madrasah' => Setting::get('madrasah_name', 'MTs'),
                'nomor' => $data['number'] ?? $letter->number,
                'perihal' => $data['subject'],
                'tanggal' => $data['letter_date'],
                'tujuan' => $data['from_to'],
            ]
        );

        return redirect()->route('persuratan.index')
            ->with('success', 'Surat berhasil disimpan.');
    }

    public function show(Letter $letter): Response
    {
        $verification = DocumentVerification::where('document_type', DocumentVerification::TYPE_SURAT_KELUAR)
            ->where('verifiable_type', Letter::class)
            ->where('verifiable_id', $letter->getKey())
            ->first();

        return Inertia::render('Persuratan/Show', [
            'letter' => $letter->load('creator:id,name'),
            'categories' => $this->categories(),
            'verification' => $verification ? [
                'token' => $verification->token,
                'url' => $verification->verifyUrl(),
            ] : null,
        ]);
    }

    public function edit(Letter $letter): Response
    {
        return Inertia::render('Persuratan/Form', [
            'letter' => $letter,
            'type' => $letter->type,
            'categories' => $this->categories(),
        ]);
    }

    public function update(Request $request, Letter $letter): RedirectResponse
    {
        $data = $request->validate([
            'number' => 'nullable|string|max:100',
            'subject' => 'required|string|max:500',
            'category' => 'required|string',
            'letter_date' => 'required|date',
            'received_date' => 'nullable|date',
            'from_to' => 'required|string|max:255',
            'description' => 'nullable|string',
            'disposisi' => 'nullable|string',
            'status' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
        ]);

        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('persuratan', 'public');
            $data['attachment_path'] = '/storage/'.$attachmentPath;
        }

        $letter->update($data);

        return redirect()->route('persuratan.index')
            ->with('success', 'Surat berhasil diperbarui.');
    }

    public function destroy(Letter $letter): RedirectResponse
    {
        $letter->delete();

        return redirect()->route('persuratan.index')
            ->with('success', 'Surat berhasil dihapus.');
    }

    /** Nomor referensi untuk auto-generate */
    private function getLembagaCode(): string
    {
        $nsm = Setting::get('nsm', '');
        $name = Setting::get('madrasah_name', 'MTs');

        // Ambil singkatan dari nama madrasah
        if (str_contains(strtolower($name), 'tsanawiyah') || str_contains(strtolower($name), 'mts')) {
            return 'MTs.NM';
        }
        if (str_contains(strtolower($name), 'ibtidaiyah') || str_contains(strtolower($name), 'mi ')) {
            return 'MI.NM';
        }

        return 'MTsN.NM';
    }

    private function categories(): array
    {
        return [
            'umum', 'undangan', 'sk', 'keterangan',
            'rekomendasi', 'permohonan', 'pemberitahuan', 'edaran', 'lainnya',
        ];
    }
}
