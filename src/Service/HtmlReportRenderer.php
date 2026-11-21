<?php

declare(strict_types=1);

namespace M2Performance\Service;

use M2Performance\Model\Recommendation;

/**
 * Interactive, self-contained HTML report.
 *
 * UX modeled on the Playwright HTML report: clickable stat chips as priority
 * filters, live search, area filter, sortable list, expandable rows with the
 * complete finding detail (all affected files, explanation, metadata — no
 * "--export for full list" truncation), light/dark via prefers-color-scheme.
 * No external dependencies: single file, works offline and over file://.
 */
class HtmlReportRenderer
{
    /**
     * @param Recommendation[] $recommendations
     * @param array{score: float, grade: string}|null $score
     */
    public function render(
        array $recommendations,
        ?string $baseSecureUrl = null,
        ?array $score = null,
        ?string $toolVersion = null
    ): string {
        $items = [];
        $areas = [];
        $counts = ['high' => 0, 'medium' => 0, 'low' => 0];

        foreach ($recommendations as $rec) {
            $priorityKey = match ($rec->getPriority()) {
                Recommendation::PRIORITY_HIGH => 'high',
                Recommendation::PRIORITY_MEDIUM => 'medium',
                default => 'low',
            };
            $counts[$priorityKey]++;
            $areas[$rec->getArea()] = true;

            $items[] = [
                'area' => $rec->getArea(),
                'priority' => $priorityKey,
                'priorityRank' => $rec->getPriority(),
                'title' => $rec->getTitle(),
                'details' => $this->stripExportHints($rec->getDetails()),
                'explanation' => $rec->getExplanation(),
                'files' => $rec->getFiles(),
                'metadata' => $this->presentableMetadata($rec->getMetadata()),
            ];
        }

        ksort($areas);

        $payload = json_encode([
            'generatedAt' => date('Y-m-d H:i:s T'),
            'baseUrl' => $baseSecureUrl,
            'score' => $score,
            'toolVersion' => $toolVersion,
            'counts' => $counts,
            'total' => count($items),
            'areas' => array_keys($areas),
            'items' => $items,
        ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return str_replace('__REPORT_DATA__', (string)$payload, $this->template());
    }

    /**
     * The CLI table appends "💡 Full list ... available with --export" hints.
     * The report IS the export: strip them and show everything.
     */
    private function stripExportHints(string $details): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $details);
        if (is_array($lines) === false) {
            return $details;
        }
        $kept = array_filter($lines, static function (string $line): bool {
            return str_contains($line, 'available with --export') === false;
        });

        return trim(implode("\n", $kept));
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, string>
     */
    private function presentableMetadata(array $metadata): array
    {
        $out = [];
        foreach ($metadata as $key => $value) {
            if (is_scalar($value) === true) {
                $out[(string)$key] = (string)$value;
            } else {
                $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
                $out[(string)$key] = is_string($encoded) === true ? $encoded : '';
            }
        }

        return $out;
    }

    private function template(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html style="scrollbar-gutter: stable both-edges;">
<head>
<meta charset="UTF-8">
<meta name="color-scheme" content="dark light">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>M2 Performance Report</title>
<style>
:root{
  --bg:#ffffff;--fg:#1f2430;--muted:#6b7280;--card:#f8f9fb;--border:#e5e7eb;
  --chip:#eef0f4;--chip-active:#1f2430;--chip-active-fg:#ffffff;
  --high:#d23f31;--medium:#e8a000;--low:#0f9d58;--accent:#1a73e8;
  --code:#f1f3f6;
}
@media (prefers-color-scheme: dark){
  :root{
    --bg:#111418;--fg:#e6e8ee;--muted:#9aa2b1;--card:#1a1f26;--border:#2a313b;
    --chip:#232a33;--chip-active:#e6e8ee;--chip-active-fg:#111418;
    --high:#ff6b5e;--medium:#f2b53c;--low:#3ecf7c;--accent:#6aa9ff;
    --code:#0d1117;
  }
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);
  font:14px/1.55 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}
.wrap{max-width:1180px;margin:0 auto;padding:24px 20px 64px}
header{display:flex;flex-wrap:wrap;align-items:baseline;gap:12px 18px;margin-bottom:6px}
h1{font-size:20px;font-weight:650;margin:0}
.meta{color:var(--muted);font-size:12.5px}
.meta a{color:var(--accent);text-decoration:none}
.scoreband{display:flex;align-items:center;gap:10px;margin:10px 0 18px}
.grade{font-size:26px;font-weight:700;padding:2px 14px;border-radius:8px;background:var(--card);border:1px solid var(--border)}
.grade.g-A{color:var(--low)}.grade.g-B{color:var(--low)}.grade.g-C{color:var(--medium)}
.grade.g-D,.grade.g-F{color:var(--high)}
.toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:14px 0 18px;position:sticky;top:0;background:var(--bg);padding:10px 0;z-index:5;border-bottom:1px solid var(--border)}
.chips{display:flex;gap:8px;flex-wrap:wrap}
.chip{border:1px solid var(--border);background:var(--chip);color:var(--fg);
  border-radius:16px;padding:4px 12px;cursor:pointer;font-size:13px;user-select:none}
.chip .n{font-weight:700;margin-left:4px}
.chip.active{background:var(--chip-active);color:var(--chip-active-fg);border-color:var(--chip-active)}
.chip .dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;vertical-align:1px}
.dot.high{background:var(--high)}.dot.medium{background:var(--medium)}.dot.low{background:var(--low)}
input[type=search],select{background:var(--card);color:var(--fg);border:1px solid var(--border);
  border-radius:8px;padding:7px 10px;font-size:13px;outline:none;min-width:120px}
input[type=search]{flex:1;min-width:220px}
input[type=search]:focus,select:focus{border-color:var(--accent)}
.sortbtn{background:none;border:none;color:var(--muted);cursor:pointer;font-size:12.5px;padding:4px 6px}
.sortbtn.active{color:var(--accent);font-weight:600}
.count-line{color:var(--muted);font-size:12.5px;margin:4px 0 12px}
.item{border:1px solid var(--border);border-left-width:4px;border-radius:10px;background:var(--card);margin-bottom:10px;overflow:hidden}
.item.p-high{border-left-color:var(--high)}
.item.p-medium{border-left-color:var(--medium)}
.item.p-low{border-left-color:var(--low)}
.row{display:flex;gap:12px;align-items:center;padding:11px 14px;cursor:pointer}
.row:hover{background:color-mix(in srgb, var(--card) 70%, var(--bg))}
.badge{font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;
  padding:2px 8px;border-radius:6px;flex:none;min-width:64px;text-align:center}
.badge.high{background:color-mix(in srgb, var(--high) 16%, transparent);color:var(--high)}
.badge.medium{background:color-mix(in srgb, var(--medium) 18%, transparent);color:var(--medium)}
.badge.low{background:color-mix(in srgb, var(--low) 16%, transparent);color:var(--low)}
.area-tag{font-size:11.5px;color:var(--muted);background:var(--chip);border-radius:6px;padding:2px 8px;flex:none}
.title{font-weight:600;flex:1;min-width:0}
.caret{color:var(--muted);flex:none;transition:transform .15s}
.item.open .caret{transform:rotate(90deg)}
.body{display:none;padding:2px 16px 14px;border-top:1px solid var(--border)}
.item.open .body{display:block}
.body h4{margin:12px 0 4px;font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted)}
.body pre{background:var(--code);border:1px solid var(--border);border-radius:8px;
  padding:10px 12px;overflow-x:auto;white-space:pre-wrap;word-break:break-word;font-size:12.5px;margin:4px 0}
.files{list-style:none;margin:4px 0;padding:0}
.files li{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;
  padding:3px 0;border-bottom:1px dashed var(--border);word-break:break-all}
.files li:last-child{border-bottom:none}
mark{background:color-mix(in srgb, var(--accent) 30%, transparent);color:inherit;border-radius:2px}
.empty{color:var(--muted);text-align:center;padding:40px 0}
.footer{margin-top:36px;color:var(--muted);font-size:12px;text-align:center}
.kbd{border:1px solid var(--border);border-bottom-width:2px;border-radius:4px;padding:0 5px;font-size:11px;background:var(--card)}
</style>
</head>
<body>
<div class="wrap">
  <header>
    <h1>Magento 2 Performance Report</h1>
    <span class="meta" id="meta-line"></span>
  </header>
  <div class="scoreband" id="scoreband"></div>

  <div class="toolbar">
    <div class="chips" id="chips"></div>
    <select id="area-filter"><option value="">All areas</option></select>
    <input type="search" id="q" placeholder="Search title, details, files…  ( / to focus )">
    <span>
      <button class="sortbtn active" data-sort="priority">Sort: Priority</button>
      <button class="sortbtn" data-sort="area">Area</button>
      <button class="sortbtn" data-sort="title">Title</button>
    </span>
  </div>

  <div class="count-line" id="count-line"></div>
  <div id="list"></div>
  <div class="empty" id="empty" style="display:none">No findings match the current filters.</div>

  <div class="footer" id="footer"></div>
</div>

<script id="report-data" type="application/json">__REPORT_DATA__</script>
<script>
(function(){
  const data = JSON.parse(document.getElementById('report-data').textContent);
  const state = { priority: '', area: '', q: '', sort: 'priority', open: new Set() };

  const esc = s => String(s ?? '').replace(/[&<>"']/g,
    c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  const hl = (text, q) => {
    const t = esc(text);
    if (q === '') { return t; }
    const i = t.toLowerCase().indexOf(esc(q).toLowerCase());
    if (i < 0) { return t; }
    return t.slice(0,i) + '<mark>' + t.slice(i, i+q.length) + '</mark>' + t.slice(i+q.length);
  };

  // Header / meta
  const metaBits = [];
  if (data.baseUrl) { metaBits.push('Target: <a href="' + esc(data.baseUrl) + '">' + esc(data.baseUrl) + '</a>'); }
  else { metaBits.push('Target: <em>base URL not resolvable</em>'); }
  metaBits.push('Generated ' + esc(data.generatedAt));
  if (data.toolVersion) { metaBits.push('Tool v' + esc(data.toolVersion)); }
  document.getElementById('meta-line').innerHTML = metaBits.join(' · ');

  if (data.score) {
    const g = String(data.score.grade || '?').charAt(0);
    document.getElementById('scoreband').innerHTML =
      '<span class="grade g-' + esc(g) + '">' + esc(data.score.grade) + '</span>' +
      '<span>Score <strong>' + esc(data.score.score) + '</strong>/100 · ' + data.total + ' findings</span>';
  } else {
    document.getElementById('scoreband').innerHTML = '<span>' + data.total + ' findings</span>';
  }

  // Filter chips (Playwright-style: All / per-priority with counts)
  const chipDefs = [
    { key: '', label: 'All', n: data.total, dot: null },
    { key: 'high', label: 'High', n: data.counts.high, dot: 'high' },
    { key: 'medium', label: 'Medium', n: data.counts.medium, dot: 'medium' },
    { key: 'low', label: 'Low', n: data.counts.low, dot: 'low' },
  ];
  const chips = document.getElementById('chips');
  chipDefs.forEach(d => {
    const b = document.createElement('button');
    b.className = 'chip' + (state.priority === d.key ? ' active' : '');
    b.innerHTML = (d.dot ? '<span class="dot ' + d.dot + '"></span>' : '') +
      esc(d.label) + '<span class="n">' + d.n + '</span>';
    b.onclick = () => { state.priority = d.key; renderChips(); render(); };
    b.dataset.key = d.key;
    chips.appendChild(b);
  });
  function renderChips(){
    chips.querySelectorAll('.chip').forEach(c =>
      c.classList.toggle('active', c.dataset.key === state.priority));
  }

  // Area filter
  const areaSel = document.getElementById('area-filter');
  data.areas.forEach(a => {
    const o = document.createElement('option'); o.value = a; o.textContent = a;
    areaSel.appendChild(o);
  });
  areaSel.onchange = () => { state.area = areaSel.value; render(); };

  // Search (with / shortcut like Playwright)
  const q = document.getElementById('q');
  q.oninput = () => { state.q = q.value.trim(); render(); };
  document.addEventListener('keydown', e => {
    if (e.key === '/' && document.activeElement !== q) { e.preventDefault(); q.focus(); }
    if (e.key === 'Escape' && document.activeElement === q) { q.value=''; state.q=''; render(); q.blur(); }
  });

  // Sort buttons
  document.querySelectorAll('.sortbtn').forEach(b => {
    b.onclick = () => {
      state.sort = b.dataset.sort;
      document.querySelectorAll('.sortbtn').forEach(x => x.classList.toggle('active', x === b));
      render();
    };
  });

  function matches(it){
    if (state.priority !== '' && it.priority !== state.priority) { return false; }
    if (state.area !== '' && it.area !== state.area) { return false; }
    if (state.q !== '') {
      const needle = state.q.toLowerCase();
      const hay = (it.title + '\n' + it.details + '\n' + (it.explanation||'') + '\n' +
                   it.files.join('\n') + '\n' + it.area).toLowerCase();
      if (hay.indexOf(needle) < 0) { return false; }
    }
    return true;
  }

  function sorted(items){
    const arr = items.slice();
    if (state.sort === 'priority') { arr.sort((a,b) => b.priorityRank - a.priorityRank || a.area.localeCompare(b.area)); }
    if (state.sort === 'area') { arr.sort((a,b) => a.area.localeCompare(b.area) || b.priorityRank - a.priorityRank); }
    if (state.sort === 'title') { arr.sort((a,b) => a.title.localeCompare(b.title)); }
    return arr;
  }

  function render(){
    const list = document.getElementById('list');
    const visible = sorted(data.items.map((it,i) => ({...it, _i:i})).filter(matches));
    document.getElementById('count-line').textContent =
      'Showing ' + visible.length + ' of ' + data.total + ' findings';
    document.getElementById('empty').style.display = visible.length ? 'none' : 'block';
    list.innerHTML = visible.map(it => {
      const open = state.open.has(it._i) ? ' open' : '';
      const filesHtml = it.files.length
        ? '<h4>Affected files (' + it.files.length + ')</h4><ul class="files">' +
          it.files.map(f => '<li>' + hl(f, state.q) + '</li>').join('') + '</ul>'
        : '';
      const explHtml = it.explanation
        ? '<h4>Why it matters</h4><div>' + hl(it.explanation, state.q) + '</div>' : '';
      const metaKeys = Object.keys(it.metadata || {});
      const metaHtml = metaKeys.length
        ? '<h4>Metadata</h4><pre>' + esc(metaKeys.map(k => k + ': ' + it.metadata[k]).join('\n')) + '</pre>'
        : '';
      return '<div class="item p-' + it.priority + open + '" data-i="' + it._i + '">' +
        '<div class="row">' +
          '<span class="caret">▶</span>' +
          '<span class="badge ' + it.priority + '">' + it.priority + '</span>' +
          '<span class="area-tag">' + esc(it.area) + '</span>' +
          '<span class="title">' + hl(it.title, state.q) + '</span>' +
        '</div>' +
        '<div class="body">' +
          '<h4>Details</h4><pre>' + hl(it.details, state.q) + '</pre>' +
          explHtml + filesHtml + metaHtml +
        '</div></div>';
    }).join('');
    list.querySelectorAll('.row').forEach(r => {
      r.onclick = () => {
        const el = r.parentElement, i = Number(el.dataset.i);
        el.classList.toggle('open');
        el.classList.contains('open') ? state.open.add(i) : state.open.delete(i);
      };
    });
  }

  document.getElementById('footer').innerHTML =
    'Generated by M2 Performance Review Tool' +
    (data.toolVersion ? ' v' + esc(data.toolVersion) : '') +
    ' · Press <span class="kbd">/</span> to search';

  render();
})();
</script>
</body>
</html>
HTML;
    }
}
