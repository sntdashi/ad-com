"""Generate native editable draw.io cells, never embedded diagram screenshots."""

from pathlib import Path
from html import escape
import re
import xml.etree.ElementTree as ET

BASE = Path(__file__).resolve().parent
SCALE = 1.875
NS = {"s": "http://www.w3.org/2000/svg"}
FONT = "fontFamily=Helvetica;fontSize=15;fontColor=#27272F;whiteSpace=wrap;html=1;"
NODE = FONT + "strokeColor=#797981;fillColor=#FFFFFF;strokeWidth=1.2;"


class Page:
    def __init__(self, file, name, width=1200, height=1300):
        diagram = ET.SubElement(file, "diagram", id=name.split()[0], name=name)
        self.model = ET.SubElement(diagram, "mxGraphModel", dx="0", dy="0", grid="1", gridSize="10", guides="1", tooltips="1", connect="1", arrows="1", fold="1", page="1", pageScale="1", pageWidth=str(width), pageHeight=str(height), math="0", shadow="0", background="#ffffff")
        self.root = ET.SubElement(self.model, "root")
        ET.SubElement(self.root, "mxCell", id="0")
        ET.SubElement(self.root, "mxCell", id="1", parent="0")
        self.boxes = {}
        self.count = 0
        self.node("title", f"AD COM / TEFA TKJ - {name}", 35, 20, width-70, 35,
                  FONT + "text;strokeColor=none;fillColor=none;align=left;fontSize=22;fontStyle=1;")

    def node(self, id, label, x, y, w, h, style=NODE+"rounded=1;arcSize=12;", parent="1"):
        px, py = self.boxes[parent][:2] if parent in self.boxes else (0, 0)
        cell = ET.SubElement(self.root, "mxCell", id=id, value=escape(label).replace("\n", "<br>"), style=style, vertex="1", parent=parent)
        ET.SubElement(cell, "mxGeometry", x=str(round(x-px, 2)), y=str(round(y-py, 2)), width=str(round(w, 2)), height=str(round(h, 2)), **{"as": "geometry"})
        self.boxes[id] = (x, y, w, h)
        return id

    def edge(self, source, target, label="", style="", points=None, id=None):
        self.count += 1
        id = id or f"edge-{self.count}"
        cell = ET.SubElement(self.root, "mxCell", id=id, value=escape(label).replace("\n", "<br>"), style=FONT+"edgeStyle=orthogonalEdgeStyle;rounded=0;strokeColor=#52525B;strokeWidth=1.3;endArrow=block;endFill=1;labelBackgroundColor=#FFFFFF;"+style, edge="1", parent="1", source=source, target=target)
        geo = ET.SubElement(cell, "mxGeometry", relative="1", **{"as": "geometry"})
        if points:
            array = ET.SubElement(geo, "Array", **{"as": "points"})
            for x, y in points:
                ET.SubElement(array, "mxPoint", x=str(round(x, 2)), y=str(round(y, 2)))
        return id

    def note(self, id, label, x, y, w, h):
        return self.node(id, label, x, y, w, h, NODE+"shape=note;size=16;fillColor=#F8F7F3;align=left;spacing=10;")

    def lanes(self, labels, height):
        for i, label in enumerate(labels):
            self.node(f"lane-{i}", label, 35+i*300, 95, 300, height,
                      NODE+"swimlane;startSize=40;container=1;collapsible=0;fillColor=#F7F7F9;swimlaneFillColor=#FFFFFF;fontStyle=1;")

    def action(self, id, label, lane, y, h=64):
        return self.node(id, label, 65+lane*300, y, 240, h, NODE+"rounded=1;arcSize=16;fillColor=#F8F3F4;", f"lane-{lane}")

    def decision(self, id, label, lane, y):
        return self.node(id, label, 85+lane*300, y, 200, 76, NODE+"rhombus;perimeter=rhombusPerimeter;", f"lane-{lane}")

    def state(self, id, lane, y, final=False):
        return self.node(id, "", 173+lane*300, y, 24, 24, NODE+f"shape={'endState' if final else 'startState'};fillColor=#27272F;", f"lane-{lane}")

    def merge(self, id, lane, y):
        return self.node(id,"",177+lane*300,y,16,16,NODE+"rhombus;perimeter=rhombusPerimeter;",f"lane-{lane}")


def native_from_layout(file, stem, name):
    """Reuse positions only; classes, actors, ovals, notes and edges are native cells."""
    svg = ET.parse(BASE / "rendered" / f"{stem}.svg").getroot()
    puml = (BASE / f"{stem}.puml").read_text()
    width = float(svg.get("width").replace("px", ""))/SCALE+100
    height = float(svg.get("height").replace("px", ""))/SCALE+140
    page = Page(file, name, round(width), round(height))
    offset = lambda x, y: (float(x)/SCALE+40, float(y)/SCALE+85)
    aliases = {}
    actors = {alias: label.replace("\\n", "\n") for label, alias in re.findall(r'actor "(.*?)" as (\w+)', puml)}
    uses = {alias: label.replace("\\n", "\n") for label, alias in re.findall(r'usecase "(.*?)" as (\w+)', puml)}
    classes = {name: body for name, body in re.findall(r'class (\w+) <<model>> \{(.*?)\n\}', puml, re.S)}
    cluster_id = None
    for group in svg.findall(".//s:g[@class='cluster']", NS):
        rect = group.find("s:rect", NS)
        if rect is not None:
            x, y = offset(rect.get("x"), rect.get("y"))
            cluster_id = group.get("id")
            page.node(cluster_id, group.get("data-qualified-name"), x, y, float(rect.get("width"))/SCALE, float(rect.get("height"))/SCALE,
                      NODE+"rounded=0;verticalAlign=top;spacingTop=12;fontStyle=1;container=1;collapsible=0;")
    for group in svg.findall(".//s:g[@class='entity']", NS):
        full_name = group.get("data-qualified-name")
        name = full_name.split(".")[-1]
        id = group.get("id")
        aliases[name] = id
        if name in classes:
            rect = group.find("s:rect", NS)
            x, y = offset(rect.get("x"), rect.get("y"))
            w, h = float(rect.get("width"))/SCALE, float(rect.get("height"))/SCALE
            lines = group.findall("s:line", NS)
            dividers = sorted({float(line.get("y1"))/SCALE+85 for line in lines})
            header_h = dividers[0]-y
            page.node(id, "«model»\n"+name, x, y, w, h, NODE+f"swimlane;startSize={header_h};container=1;collapsible=0;fillColor=#F6ECEE;swimlaneFillColor=#FFFFFF;fontStyle=1;")
            parts = classes[name].strip().split("\n  --\n")
            attrs = "\n".join(line.strip() for line in parts[0].splitlines())
            split = dividers[1] if len(dividers)>1 else y+h
            page.node(id+"-attrs", attrs, x, y+header_h, w, split-y-header_h,
                      FONT+"text;fillColor=none;strokeColor=none;align=left;verticalAlign=top;spacing=7;", id)
            if len(parts)>1:
                methods = "\n".join(line.strip() for line in parts[1].splitlines())
                page.node(id+"-methods", methods, x, split, w, y+h-split,
                          FONT+"shape=partialRectangle;top=1;bottom=0;left=0;right=0;fillColor=none;strokeColor=#797981;align=left;verticalAlign=top;spacing=7;", id)
        elif name in actors:
            ellipse = group.find("s:ellipse", NS)
            x, y = offset(float(ellipse.get("cx"))-24.375, float(ellipse.get("cy"))-15)
            page.node(id, actors[name], x, y, 48.75/SCALE, 108.75/SCALE,
                      NODE+"shape=umlActor;verticalLabelPosition=bottom;verticalAlign=top;labelPosition=center;align=center;fillColor=none;whiteSpace=nowrap;")
        elif name in uses:
            ellipse = group.find("s:ellipse", NS)
            x, y = offset(float(ellipse.get("cx"))-float(ellipse.get("rx")), float(ellipse.get("cy"))-float(ellipse.get("ry")))
            page.node(id, uses[name], x, y, float(ellipse.get("rx"))*2/SCALE, float(ellipse.get("ry"))*2/SCALE,
                      NODE+"ellipse;perimeter=ellipsePerimeter;fillColor=#FAF7F7;strokeColor=#9A5360;", cluster_id or "1")
        else:
            texts = group.findall("s:text", NS)
            if not texts:
                continue
            path = group.find("s:path", NS)
            pairs = re.findall(r"(-?\d+(?:\.\d+)?)[ ,](-?\d+(?:\.\d+)?)", path.get("d", "")) if path is not None else []
            if pairs:
                xs, ys = zip(*[(float(a), float(b)) for a, b in pairs])
                x, y = offset(min(xs), min(ys))
                page.note(id, "\n".join("".join(t.itertext()) for t in texts), x, y, (max(xs)-min(xs))/SCALE, (max(ys)-min(ys))/SCALE)
    for group in svg.findall(".//s:g[@class='link']", NS):
        src, dst = group.get("data-entity-1"), group.get("data-entity-2")
        if src not in page.boxes or dst not in page.boxes:
            continue
        texts = group.findall("s:text", NS)
        kind = group.get("data-link-type")
        label_parts = ["".join(t.itertext()) for t in texts]
        label = "\n".join(s for s in label_parts if not re.fullmatch(r"[\d.*]+", s))
        style = "edgeStyle=none;endArrow=none;"
        if kind == "extension":
            style += "endArrow=block;endFill=0;endSize=20;"
        if "extend" in label:
            style += "dashed=1;endArrow=open;endFill=0;"
        path = group.find("s:path", NS)
        pairs = re.findall(r"(-?\d+(?:\.\d+)?)[ ,](-?\d+(?:\.\d+)?)", path.get("d", "")) if path is not None else []
        points = [offset(a,b) for a,b in pairs]
        if len(points)>2:
            style += "curved=1;"
        if points:
            for terminal, point, prefix in [(src, points[0], "exit"), (dst, points[-1], "entry")]:
                x, y, w, h = page.boxes[terminal]
                rx, ry = max(0,min(1,(point[0]-x)/w)), max(0,min(1,(point[1]-y)/h))
                style += f"{prefix}X={rx};{prefix}Y={ry};{prefix}Perimeter=1;"
        edge_id = page.edge(src, dst, "", style, points[1:-1], group.get("id"))
        for i, text in enumerate(texts):
            value = "".join(text.itertext())
            x,y = offset(text.get("x"), float(text.get("y"))-24)
            w = max(25,float(text.get("textLength", len(value)*16))/SCALE)
            page.node(edge_id+f"-label-{i}", value, x-2, y, w+6, 22,
                      FONT+"text;strokeColor=none;fillColor=#FFFFFF;align=left;spacing=0;")
    page.note("legend", "1 = tepat satu; 0..1 = opsional; 0..* = nol/banyak. Kelas yang berulang tetap kelas yang sama." if classes else "Aktor di luar batas sistem. Garis asosiasi bukan urutan waktu. Segitiga kosong = generalisasi aktor.", 35, height-48, width-70, 40)
    return page


def intake(file):
    p = Page(file, "03 Activity - Pemesanan", 1000, 1560)
    p.lanes(["Pemohon (tamu / pelanggan)", "Sistem AD Com", "Pengelola pesanan"], 1380)
    p.state("start",0,150)
    p.action("form","Pilih layanan aktif; isi kontak,\nalamat, keluhan dan persetujuan privasi",0,200,80)
    p.action("validate","Validasi formulir\ndan layanan aktif",1,315)
    p.decision("valid","Data valid?",1,420)
    p.action("error","Tampilkan kesalahan\nuntuk diperbaiki",0,415)
    p.action("create","Transaksi: buat tiket new,\nhash token/kode dan event awal",1,545,80)
    p.action("receive","Terima nomor tiket, kode akses\ndan halaman pelacakan",0,665)
    p.decision("cancel","Batalkan\npermintaan?",2,665)
    p.action("cancelled","Status cancelled",1,805)
    p.state("cancel-end",1,905,True)
    p.decision("info","Perlu info\ntambahan?",2,790)
    p.action("needs","Status needs_info;\nkonfirmasi informasi",0,895)
    p.action("triage","Status triaged",2,1010)
    p.decision("estimate","Perlu estimasi /\npersetujuan?",2,1115)
    p.action("approve","waiting_estimate (opsional)\nlalu waiting_approval;\nkonfirmasi biaya secara operasional",0,1110,94)
    p.decision("accepted","Dilanjutkan?",1,1115)
    p.action("schedule","Tetapkan PIC/anggota;\nkonfirmasi jadwal; status scheduled",2,1290,80)
    p.state("end",2,1410,True)
    p.merge("form-merge",0,180)
    p.merge("cancel-merge",1,778)
    p.merge("triage-merge",2,984)
    p.merge("schedule-merge",2,1255)
    for s,t,l in [("start","form-merge",""),("form-merge","form",""),("form","validate",""),("validate","valid",""),("valid","error","tidak"),("error","form-merge",""),("valid","create","ya"),("create","receive",""),("receive","cancel",""),("cancel","cancel-merge","ya"),("cancel-merge","cancelled",""),("cancelled","cancel-end",""),("cancel","info","tidak"),("info","needs","ya"),("needs","triage-merge",""),("info","triage-merge","tidak"),("triage-merge","triage",""),("triage","estimate",""),("estimate","approve","ya"),("approve","accepted",""),("accepted","schedule-merge","ya"),("estimate","schedule-merge","tidak"),("schedule-merge","schedule",""),("schedule","end","")]:
        p.edge(s,t,l)
    p.edge("accepted","cancel-merge","tidak",points=[(510,1090),(625,1090),(625,786)])
    p.note("note","Pengelola = operator/admin/supervisor. Akhir diagram adalah akhir subproses penerimaan; lanjut diagram 04. Persetujuan biaya belum memiliki endpoint mandiri pelanggan.",35,1490,900,60)


def work(file):
    p = Page(file, "04 Activity - Pengerjaan dan Rating", 1300, 1530)
    p.lanes(["Teknisi ditugaskan", "Pengelola pesanan", "Sistem AD Com", "Pelanggan pemilik pesanan"],1330)
    p.state("start",0,145)
    p.action("scheduled","Buka tiket scheduled;\nubah ke on_the_way",0,190)
    p.action("work","Status in_progress;\nlaksanakan pekerjaan",0,295)
    p.action("verify","Ajukan verifikasi\n(status verification)",0,405)
    p.action("check","Periksa hasil pekerjaan",1,500)
    p.decision("rework","Perlu\nperbaikan?",1,595)
    p.decision("payment","Tunggu\npembayaran?",1,740)
    p.action("pay","Status waiting_payment;\nkonfirmasi pembayaran secara operasional",0,740,80)
    p.action("complete","Status completed",1,895)
    p.action("save","Simpan completed_at,\nevent status dan audit",2,995)
    p.decision("rating","Ingin memberi\npenilaian?",3,1080)
    p.action("review","Pilih teknisi penugasan;\nisi rating 1-5 dan komentar opsional",3,1210,80)
    p.action("savereview","Validasi kepemilikan/status/penugasan;\nsimpan atau perbarui ulasan dan audit",2,1210,94)
    p.action("evaluation","Lihat evaluasi bila tersedia;\nkelola garansi / penutupan",1,1330)
    p.state("end",0,1350,True)
    p.merge("work-merge",0,276)
    p.merge("complete-merge",1,870)
    p.merge("evaluation-merge",1,1305)
    for s,t,l in [("start","scheduled",""),("scheduled","work-merge",""),("work-merge","work",""),("work","verify",""),("verify","check",""),("check","rework",""),("rework","payment","tidak"),("payment","pay","ya"),("pay","complete-merge",""),("payment","complete-merge","tidak"),("complete-merge","complete",""),("complete","save",""),("save","rating",""),("rating","review","ya"),("review","savereview",""),("savereview","evaluation-merge",""),("evaluation-merge","evaluation",""),("evaluation","end","")]:
        p.edge(s,t,l)
    p.edge("rework","work-merge","ya / kembalikan in_progress",points=[(345,635),(345,284)])
    p.edge("rating","evaluation-merge","tidak",points=[(1260,1120),(1260,1410),(630,1410),(630,1313)])
    p.note("note","Pengelola = operator/admin/supervisor. Keuangan dicatat admin/operator dan diverifikasi operator, bukan payment gateway. Verifikasi transaksi tidak otomatis mengubah status tiket.",35,1440,1200,70)


def sequence(file):
    p = Page(file,"05 Sequence - Membuat Tiket",1400,1420)
    people = [("customer","Pemohon",100),("ui","booking.tsx\nForm web",350),("http","Laravel HTTP\nRequest + Controller",630),("action","CreateServiceOrder",925),("db","Database",1210)]
    for id,label,x in people:
        p.node(id,label,x-85,100,170,1240,NODE+"shape=umlLifeline;perimeter=lifelinePerimeter;size=65;participant=rectangle;",parent="1")
    p.node("alt","alt",235,350,1090,990,NODE+"shape=umlFrame;fillColor=none;size=50;")
    p.node("invalid-label","[data tidak valid]",255,388,250,25,FONT+"text;strokeColor=none;fillColor=none;align=left;fontStyle=1;")
    p.node("valid-label","[data valid]",255,555,230,25,FONT+"text;strokeColor=none;fillColor=none;align=left;fontStyle=1;")
    p.node("transaction","group: transaksi database atomik",835,665,490,258,NODE+"shape=umlFrame;fillColor=none;size=260;")
    p.edge("alt","alt","","edgeStyle=none;endArrow=none;dashed=1;exitX=0;entryX=1;exitY=0.19;entryY=0.19;exitPerimeter=0;entryPerimeter=0;")
    positions = {id:x for id,_,x in people}
    def msg(src,dst,y,text,response=False):
        sy=(y-100)/1240
        style=f"edgeStyle=none;exitX=0.5;exitY={sy};entryX=0.5;entryY={sy};exitPerimeter=0;entryPerimeter=0;verticalAlign=bottom;align=center;endArrow={'open' if response else 'block'};endFill={0 if response else 1};fontSize=14;"
        if response:
            style+="dashed=1;"
        points=None
        if src==dst:
            style+=f"entryY={(y+35-100)/1240};"
            points=[(positions[src]+65,y),(positions[src]+65,y+35)]
        p.edge(src,dst,text,style,points)
    msg("customer","ui",205,"1. Isi dan kirim formulir")
    msg("ui","http",260,"2. POST /pesan")
    msg("http","db",305,"3. Normalisasi/validasi; cek layanan aktif")
    msg("http","ui",440,"4a. Redirect kembali + error sesi",True)
    msg("ui","customer",490,"5a. Tampilkan kesalahan",True)
    msg("http","action",615,"4b. handle(data, user atau null)")
    msg("action","db",725,"5b. BEGIN; baca ulang layanan")
    msg("action","db",785,"6b. INSERT order new; hash token/kode")
    msg("action","db",840,"7b. INSERT event status awal")
    msg("action","db",895,"8b. COMMIT")
    msg("action","http",975,"9b. Order + token + kode akses",True)
    msg("http","ui",1030,"10b. Redirect pelacakan + flash tiket/kode",True)
    msg("ui","http",1085,"11b. GET /lacak/t/{token}")
    msg("http","db",1140,"12b. Cari hash token; baca data pelacakan")
    msg("db","http",1210,"13. Data order yang diizinkan",True)
    msg("http","ui",1260,"14. Halaman track-show",True)
    msg("ui","customer",1310,"15. Tampilkan tiket dan kode akses",True)
    p.note("note","Jalur sukses saja melanjutkan pelacakan. Action membuat token/kode acak dan nomor tiket sebelum INSERT; database menyimpan hash. Kegagalan transaksi membatalkan penyimpanan. Peserta Laravel HTTP merangkum framework dan controller.",35,1350,1300,60)


def validate(file):
    pages = file.findall("diagram")
    assert len(pages)==9
    for page in pages:
        cells = page.findall(".//mxCell")
        ids = [c.get("id") for c in cells]
        assert len(ids)==len(set(ids)),page.get("name")
        known = set(ids)
        for cell in cells:
            for ref in ("parent","source","target"):
                assert cell.get(ref) is None or cell.get(ref) in known, (page.get("name"),cell.attrib)
            assert not (cell.get("vertex")=="1" and cell.get("edge")=="1")
            assert "image=" not in cell.get("style", "")
        assert len([c for c in cells if c.get("edge")=="1"])>=4
    print(f"Validated {len(pages)} native pages: unique IDs, connected edge terminals, no image embeds.")


def build():
    file = ET.Element("mxfile",host="app.diagrams.net",modified="2026-09-12T00:00:00.000Z",agent="AD Com documentation",type="device")
    native_from_layout(file,"01-use-case-pelanggan","01 Use Case - Pelanggan")
    native_from_layout(file,"02-use-case-operasional","02 Use Case - Operasional")
    intake(file)
    work(file)
    sequence(file)
    for stem,name in [("06-class-inti","06 Class - Inti"),("07-class-interaksi","07 Class - Interaksi"),("08-class-operasional","08 Class - Operasional"),("09-class-estimasi-checklist","09 Class - Estimasi dan Checklist")]:
        native_from_layout(file,stem,name)
    validate(file)
    ET.indent(file,space="  ")
    path=BASE/"AD-Com-UML.drawio"
    ET.ElementTree(file).write(path,encoding="UTF-8",xml_declaration=True)
    validate(ET.parse(path).getroot())
    print(f"Created {path} ({path.stat().st_size:,} bytes)")


if __name__=="__main__":
    build()
