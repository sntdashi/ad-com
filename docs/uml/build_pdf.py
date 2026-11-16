"""Build the AD Com UML report from locally rendered, editable PlantUML sources."""

from pathlib import Path
import re
import xml.etree.ElementTree as ET
from zipfile import ZipFile, ZIP_DEFLATED

from PIL import Image
from pypdf import PdfReader
from reportlab.lib.colors import HexColor, white
from reportlab.lib.pagesizes import A2, A3, A4, landscape
from reportlab.lib.styles import ParagraphStyle
from reportlab.pdfgen import canvas
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import Paragraph, Table, TableStyle

BASE = Path(__file__).resolve().parent
ROOT = BASE.parent.parent
PDF = BASE / "AD-Com-UML.pdf"
INK = HexColor("#27272F")
MUTED = HexColor("#63636C")
RED = HexColor("#A32131")
LINE = HexColor("#DEDEE3")
TEXT = ParagraphStyle("body", fontName="Helvetica", fontSize=10, leading=15, textColor=INK)
SMALL = ParagraphStyle("small", parent=TEXT, fontSize=9, leading=12)
TABLE = ParagraphStyle("table", parent=TEXT, fontSize=8.4, leading=11.5)

# Embed fonts so rendering does not depend on each machine's PDF substitutes.
pdfmetrics.registerFont(TTFont("Helvetica", "/usr/share/fonts/liberation/LiberationSans-Regular.ttf"))
pdfmetrics.registerFont(TTFont("Helvetica-Bold", "/usr/share/fonts/liberation/LiberationSans-Bold.ttf"))
pdfmetrics.registerFontFamily("Helvetica", normal="Helvetica", bold="Helvetica-Bold", italic="Helvetica", boldItalic="Helvetica-Bold")

DIAGRAMS = [
    ("01-use-case-pelanggan", "Use Case | Pelanggan & akses umum",
     "Pengunjung tidak dihitung sebagai role akun; Google adalah sistem eksternal. Pengaturan akun sendiri tersedia bagi semua role.",
     "Pelanggan mewarisi akses publik. Chat dan rating tetap dibatasi kepemilikan serta kondisi tiket. ADI dapat memakai panduan katalog ketika provider AI tidak aktif."),
    ("02-use-case-operasional", "Use Case | Operasional",
     "Pengelola pesanan adalah aktor abstrak untuk kemampuan bersama operator, admin, dan supervisor; bukan role ketujuh.",
     "Teknisi hanya mengakses tiket penugasannya dan evaluasi dirinya. Akun internal harus aktif serta memenuhi autentikasi, verifikasi email, dan TOTP. Rincian hak akses ada pada halaman 2."),
    ("03-activity-pemesanan", "Activity | Pengajuan sampai penjadwalan",
     "Pemohon adalah tamu/pelanggan. Pengelola adalah operator/admin/supervisor. Diagram berakhir pada akhir subproses penerimaan.",
     "Konfirmasi biaya dan jadwal merupakan tindakan operasional. Belum ada endpoint persetujuan estimasi mandiri pelanggan. Status scheduled tidak membuktikan kolom scheduled_at telah diisi otomatis."),
    ("04-activity-pengerjaan", "Activity | Pengerjaan sampai penilaian",
     "Skenario tiket terjadwal dengan teknisi yang sudah ditugaskan. Pemeriksaan dapat mengembalikan pekerjaan untuk diperbaiki.",
     "Pembayaran dicatat admin/operator dan diverifikasi operator, bukan payment gateway. Verifikasi transaksi tidak otomatis menyelesaikan tiket. Rating opsional; tamu tidak otomatis dapat menilai."),
    ("05-sequence-pemesanan", "Sequence | Pembuatan tiket web",
     "Skenario POST /pesan. Peserta Laravel HTTP merangkum framework, StoreServiceOrderRequest, BookingController, dan TrackOrderController.",
     "Action menyimpan pesanan dan event secara atomik. Respons awal membawa kredensial pelacakan; database menyimpan hash. Diagram ini tidak mengklaim kredensial sudah dikirim lewat email/SMS."),
    ("06-class-inti", "Class | Pesanan, layanan & penugasan",
     "Tampilan 1 dari 4. Atribut dipilih yang relevan; metode merupakan metode model yang benar-benar tersedia.",
     "customer_id opsional mendukung pesanan tamu. PIC dan supervisor menunjuk User; anggota tim memakai OrderAssignment. User bukan enam kelas terpisah. Lihat catatan asosiasi tambahan di diagram."),
    ("07-class-interaksi", "Class | Profil, chat & penilaian",
     "Tampilan 2 dari 4. User dan ServiceOrder adalah kelas yang sama dengan tampilan lain, bukan duplikasi entitas.",
     "Nullable pada pengirim/pelanggan mempertahankan riwayat jika akun dihapus. Saat membuat chat/rating tetap diperlukan akun yang sah. Satu ulasan per pasangan pesanan-teknisi dapat diperbarui."),
    ("08-class-operasional", "Class | Persediaan & keuangan",
     "Tampilan 3 dari 4. Mutasi dan transaksi dapat berhubungan dengan sebuah tiket atau berdiri tanpa tiket.",
     "Nilai uang berupa integer rupiah. Audit disimpan melalui query builder ke audit_logs, bukan model AuditLog. Tidak ada klaim integrasi transfer bank otomatis."),
    ("09-class-estimasi-checklist", "Class | Estimasi & template checklist",
     "Tampilan 4 dari 4. Struktur/model ini sudah ada, tetapi keberadaan kelas bukan bukti seluruh workflow pengelolaannya selesai.",
     "Pembacaan estimasi tersedia pada detail tiket. Endpoint pengelolaan/persetujuan estimasi mandiri dan pengisian checklist lengkap belum dipetakan sebagai use case aktif."),
]

TRANSITIONS = [
    ("new", "needs_info, triaged, cancelled"),
    ("needs_info", "triaged, cancelled"),
    ("triaged", "waiting_estimate, waiting_approval, scheduled"),
    ("waiting_estimate", "waiting_approval"),
    ("waiting_approval", "scheduled, cancelled"),
    ("scheduled", "on_the_way, in_progress, cancelled"),
    ("on_the_way", "in_progress"),
    ("in_progress", "verification"),
    ("verification", "in_progress, waiting_payment, completed"),
    ("waiting_payment", "completed"),
    ("completed", "warranty, reopened, closed"),
    ("warranty", "reopened, closed"),
    ("reopened", "in_progress, waiting_estimate"),
    ("closed / cancelled", "Tidak ada transisi berikutnya"),
]


def validate_sources():
    """Check model/method existence, rendered syntax, and copied transition lists."""
    model_names = {p.stem for p in (ROOT / "app/Models").glob("*.php")}
    diagram_models = set()
    for stem, *_ in DIAGRAMS:
        puml = (BASE / f"{stem}.puml").read_text()
        assert puml.count("@startuml") == puml.count("@enduml") == 1
        svg = ET.parse(BASE / "rendered" / f"{stem}.svg").getroot()
        text = " ".join(svg.itertext()).replace("\xa0", " ")
        assert not any(term in text for term in ["Syntax Error", "Please use CSS", "Cannot find Graphviz", "An error has occurred"]), stem
        for name, body in re.findall(r"class (\w+) <<model>> \{(.*?)\n\}", puml, re.S):
            diagram_models.add(name)
            source = (ROOT / "app/Models" / f"{name}.php").read_text()
            for method in re.findall(r"\+(\w+)\(\)", body):
                assert re.search(rf"function {method}\(", source), (name, method)
        with Image.open(BASE / "rendered" / f"{stem}.png") as img:
            img.verify()
    assert diagram_models == model_names, (diagram_models ^ model_names)
    status_source = (ROOT / "app/Actions/Orders/UpdateOrderStatus.php").read_text()
    manager_transitions = dict(re.findall(r"'([a-z_]+)' => \[([^]]+)\]", status_source))
    for current, targets in TRANSITIONS[:-1]:
        assert re.findall(r"'([a-z_]+)'", manager_transitions[current]) == targets.split(", "), current
    print(f"Validated {len(DIAGRAMS)} diagrams, {len(model_names)} models, their methods, and 13 transition lists.")


def paragraph(c, text, x, y, width, style=TEXT):
    p = Paragraph(text, style)
    _, height = p.wrap(width, 1000)
    p.drawOn(c, x, y - height)
    return y - height - 12


def header(c, title, size, number):
    width, height = size
    c.setPageSize(size)
    c.setFillColor(RED)
    c.setFont("Helvetica-Bold", 10)
    c.drawString(38, height - 34, "AD COM  /  TEFA TKJ")
    c.setFillColor(MUTED)
    c.setFont("Helvetica", 9)
    c.drawRightString(width - 38, height - 34, "UML - snapshot 12 September 2026")
    c.setStrokeColor(LINE)
    c.line(38, height - 46, width - 38, height - 46)
    c.setFont("Helvetica-Bold", 21)
    c.setFillColor(INK)
    c.drawString(38, height - 76, title)
    c.setFont("Helvetica", 8)
    c.setFillColor(MUTED)
    c.drawString(38, 24, "Dokumentasi implementasi lokal | Bukan pernyataan kesiapan produksi")
    c.drawRightString(width - 38, 24, f"{number:02d}")
    c.bookmarkPage(f"page-{number}")
    c.addOutlineEntry(title, f"page-{number}", level=0)


def table(c, rows, widths, x, top, style=TABLE):
    cells = [[Paragraph(str(cell), style) for cell in row] for row in rows]
    t = Table(cells, colWidths=widths)
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), HexColor("#F2E7E9")),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LINEBELOW", (0, 0), (-1, -1), .4, LINE),
        ("TOPPADDING", (0, 0), (-1, -1), 8),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 8),
        ("LEFTPADDING", (0, 0), (-1, -1), 8),
    ]))
    _, height = t.wrap(sum(widths), 2000)
    assert top - height > 48, "Table would overlap footer"
    t.drawOn(c, x, top - height)
    return top - height - 16


def build():
    validate_sources()
    c = canvas.Canvas(str(PDF), pagesize=A4, pageCompression=1)
    c.setTitle("UML AD Com - Portal Layanan TEFA TKJ")
    c.setAuthor("Dokumentasi proyek AD Com")
    c.setSubject("Use Case, Activity, Sequence dan Class Diagram berbasis implementasi lokal")

    header(c, "Rancangan sistem AD Com", A4, 1)
    y = A4[1] - 104
    y = paragraph(c, "<b>Portal layanan Teaching Factory bidang Teknik Komputer dan Jaringan.</b> AD Com menghubungkan masyarakat dengan tim teknisi sekolah untuk layanan jaringan, CCTV, servis komputer, dan kebutuhan IT.", 38, y, A4[0] - 76)
    y = paragraph(c, "Paket ini memuat <b>4 jenis UML dalam 9 diagram</b>, disusun dari route, policy, action, model, dan skema database aplikasi yang diperiksa. Identitas mahasiswa/sekolah dan format sampul kampus dapat ditambahkan sesuai instruksi dosen.", 38, y, A4[0] - 76)
    y = table(c, [
        ["Bagian", "Isi", "Halaman"],
        ["Aktor & batas akses", "Enam role akun dan hak akses utama", "2"],
        ["Use Case", "Akses publik/pelanggan dan operasional", "3-4"],
        ["Activity", "Pengajuan serta pengerjaan sampai penilaian", "5-6"],
        ["Sequence", "Pembuatan tiket melalui form web", "7"],
        ["Class", "14 model domain dalam empat tampilan", "8-11"],
        ["Status & catatan", "Transisi lengkap, batas implementasi, sumber", "12-13"],
    ], [105, 338, 76], 38, y)
    y = paragraph(c, "<b>Batas cakupan.</b> Web mencakup enam role. Android/API saat ini ditujukan untuk pelanggan dan teknisi. Dokumentasi ini tidak mengklaim seluruh fitur web sudah tersedia di Android atau aplikasi sudah siap dipublikasikan.", 38, y, A4[0] - 76)
    y = paragraph(c, "<b>Membaca Class Diagram.</b> <b>1</b> = tepat satu; <b>0..1</b> = opsional; <b>0..*</b> = nol atau banyak; <b>?</b> = nullable. Metode berawalan <b>+</b> adalah publik. Nama kelas yang berulang pada halaman lain merujuk kelas yang sama. Ini bukan ERD seluruh tabel database.", 38, y, A4[0] - 76)
    paragraph(c, "<b>Format.</b> Halaman diagram memakai A3/A2 agar label tetap terbaca; penjelasan memakai A4. File AD-Com-UML.drawio adalah berkas utama yang bisa diedit per elemen. PDF/PNG/SVG pendamping memakai tata letak PlantUML dengan isi yang sepadan.", 38, y, A4[0] - 76)
    c.showPage()

    size = landscape(A4)
    header(c, "Aktor & hak akses utama", size, 2)
    rows = [
        ["Kemampuan", "Operator", "Admin", "Supervisor", "Teknisi", "Gudang", "Pelanggan"],
        ["Profil/pengaturan sendiri", "Ya", "Ya", "Ya", "Ya", "Ya", "Ya"],
        ["Detail dan chat tiket", "Semua", "Semua", "Semua", "Ditugaskan/PIC", "Tidak", "Milik sendiri*"],
        ["Ubah status", "Transisi pengelola", "Transisi pengelola", "Transisi pengelola", "Transisi teknisi", "Tidak", "Tidak"],
        ["Tentukan PIC/anggota", "Ya", "Ya", "Ya", "Tidak", "Tidak", "Tidak"],
        ["Lihat stok", "Ya", "Ya", "Ya", "Ya", "Ya", "Tidak"],
        ["Tambah barang/mutasi", "Ya", "Ya", "Tidak", "Tidak", "Ya", "Tidak"],
        ["Catat/lihat keuangan global", "Ya", "Ya", "Tidak", "Tidak", "Tidak", "Tidak"],
        ["Verifikasi transaksi pending", "Ya", "Tidak", "Tidak", "Tidak", "Tidak", "Tidak"],
        ["Kelola akun tim", "Ya", "Non-operator", "Tidak", "Tidak", "Tidak", "Tidak"],
        ["Lihat evaluasi teknisi", "Semua", "Semua", "Semua", "Diri sendiri", "Tidak", "Ringkasan di tiket"],
        ["Tulis/perbarui rating", "Tidak", "Tidak", "Tidak", "Tidak", "Tidak", "Memenuhi syarat*"],
    ]
    y = table(c, rows, [168] + [99.65] * 6, 38, size[1] - 99)
    paragraph(c, "* Chat pelanggan memerlukan PIC. Rating hanya oleh pemilik akun pesanan setelah completed/warranty/closed, untuk teknisi yang pernah ditugaskan. Pengunjung, Google, dan aktor abstrak bukan role tambahan. Siswa adalah pelanggan kecuali secara eksplisit ditunjuk sebagai teknisi.", 38, y, size[0] - 76, SMALL)
    c.showPage()

    for page, (stem, title, intro, note) in enumerate(DIAGRAMS, start=3):
        image = BASE / "rendered" / f"{stem}.png"
        with Image.open(image) as img:
            iw, ih = img.size
        size = A3 if ih / iw > 1.12 else landscape(A3)
        if 28.125 * min((size[0]-76)/iw, (size[1]-222)/ih) < 9:
            size = A2 if ih / iw > 1.12 else landscape(A2)
        header(c, title, size, page)
        width, height = size
        paragraph(c, intro, 38, height - 99, width - 76)
        box_width, box_height = width - 76, height - 222
        scale = min(box_width / iw, box_height / ih)
        dw, dh = iw * scale, ih * scale
        x, y = (width - dw) / 2, 92 + (box_height - dh) / 2
        c.drawImage(str(image), x, y, width=dw, height=dh, mask="auto")
        paragraph(c, note, 38, 75, width - 76, SMALL)
        c.showPage()
        print(f"Page {page}: {stem}, body text ~{28.125 * scale:.1f}pt")

    header(c, "Transisi status pekerjaan", A4, 12)
    y = paragraph(c, "Daftar berikut menyalin transisi operator/admin/supervisor pada <b>UpdateOrderStatus::allowedTransitions()</b>. Diagram aktivitas menampilkan skenario utama, bukan seluruh cabang pada tabel ini.", 38, A4[1] - 104, A4[0] - 76)
    y = table(c, [["Status awal", "Status berikutnya yang diizinkan"], *TRANSITIONS], [133, 386], 38, y)
    y = paragraph(c, "<b>Batas teknisi:</b> scheduled -&gt; on_the_way; on_the_way -&gt; in_progress; in_progress -&gt; verification. Teknisi tidak langsung menetapkan completed. Perubahan harus lolos policy dan dicatat pada timeline serta audit.", 38, y, A4[0] - 76)
    paragraph(c, "<b>Rating:</b> dibuka saat completed, warranty, atau closed. Reopened/in_progress bukan status pemberian rating. Penilaian rendah merupakan masukan evaluasi manusia, bukan keputusan hukuman otomatis.", 38, y, A4[0] - 76)
    c.showPage()

    header(c, "Batas implementasi & sumber", A4, 13)
    y = A4[1] - 104
    for text in [
        "<b>Yang tidak boleh diklaim sudah lengkap.</b> Model Estimate, EstimateItem, dan ChecklistTemplate tersedia, tetapi belum ada endpoint pengelolaan/persetujuan estimasi mandiri pelanggan atau pengisian checklist lengkap pada route yang diperiksa. Konfirmasi biaya/jadwal dan pembayaran pada Activity Diagram adalah proses operasional.",
        "<b>Tabel bukan otomatis kelas/fitur.</b> approvals, work_logs, order_checklist_results, customer_assets, asset_service_records, dan reviews tersedia pada migration, namun tidak dipetakan sebagai model Eloquent aktif tersendiri. audit_logs dipakai melalui query builder. Tabel sesi/token/cache/queue tidak termasuk diagram domain.",
        "<b>Sumber implementasi utama.</b> routes/web.php, routes/api.php, routes/api-orders.php; app/Policies/ServiceOrderPolicy.php; app/Actions/Orders/CreateServiceOrder.php, UpdateOrderStatus.php, SendOrderMessage.php, SaveTechnicianReview.php; serta seluruh 14 model pada app/Models dan migration database.",
        "<b>Sumber operasional.</b> InventoryItemPolicy, FinancialTransactionPolicy, TechnicianReviewPolicy; OrderTeamController, InventoryMovementController, FinanceController, TechnicianPerformanceController; UpdateTeamMemberRequest. Pemetaan file lebih lengkap ada di README.md paket UML.",
        "<b>Notasi.</b> Asosiasi use case bukan urutan waktu. Segitiga kosong menunjukkan generalisasi; extend bersyarat menunjuk use case dasar. Activity memakai swimlane penanggung jawab dan keputusan. Sequence memakai lifeline, pesan/respons, serta fragmen alternatif. Class menampilkan struktur dan kardinalitas, bukan seluruh tabel fisik.",
    ]:
        y = paragraph(c, text, 38, y, A4[0] - 76)
    y = paragraph(c, "<b>Rujukan notasi/rendering resmi:</b><br/><link href='https://plantuml.com/use-case-diagram' color='#A32131'>plantuml.com/use-case-diagram</link><br/><link href='https://plantuml.com/activity-diagram-beta' color='#A32131'>plantuml.com/activity-diagram-beta</link><br/><link href='https://plantuml.com/sequence-diagram' color='#A32131'>plantuml.com/sequence-diagram</link><br/><link href='https://plantuml.com/class-diagram' color='#A32131'>plantuml.com/class-diagram</link>", 38, y, A4[0] - 76)
    paragraph(c, "Render: PlantUML 1.2026.8 secara lokal. Tidak memakai data pribadi atau kredensial asli dalam diagram. Snapshot dokumentasi ini bukan hasil audit keamanan atau bukti kesiapan produksi.", 38, y, A4[0] - 76, SMALL)
    c.showPage()
    c.save()
    reader = PdfReader(PDF)
    assert len(reader.pages) == 13
    for number, page in enumerate(reader.pages, start=1):
        assert "AD COM" in page.extract_text(), number
    archive = BASE / "AD-Com-UML-paket.zip"
    with ZipFile(archive, "w", ZIP_DEFLATED) as output:
        files = [PDF, BASE / "README.md", BASE / "build_pdf.py", BASE / "build_drawio.py", BASE / "theme.puml", BASE / "AD-Com-UML.drawio"]
        files += list(BASE.glob("0*.puml")) + list((BASE / "rendered").glob("*.svg")) + list((BASE / "rendered").glob("*.png"))
        for file in sorted(files):
            output.write(file, str(Path("AD-Com-UML") / file.relative_to(BASE)))
    print(f"Created {PDF} ({PDF.stat().st_size:,} bytes), 13 pages, plus editable ZIP.")


if __name__ == "__main__":
    build()
