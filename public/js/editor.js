// Shared quotation/invoice editor. Mirrors PHP Calculator policy.
const E = window.EDITOR;
const fmtIDR = n => 'Rp' + Math.round(n).toLocaleString('id-ID');
const parseIDR = v => { if (v==null||v==='') return 0; let s=String(v).replace(/[^0-9,\.\-]/g,''); if (s.includes(',')) s=s.split(',')[0]; s=s.replace(/\./g,''); return parseInt(s.replace(/[^0-9\-]/g,''))||0; };
const parseQty = v => { if (v==null||v==='') return 0; let s=String(v).trim(); s=s.replace(',', '.'); s=s.replace(/[^0-9.\-]/g,''); return parseFloat(s)||0; };
const isPcs = u => /^(pcs?|pieces?)$/i.test(String(u||'').trim());
const fmtQty = (v, unit) => { if (v==null||v==='') return ''; const s=String(v).replace(',', '.'); if (s.trim()===''||isNaN(parseFloat(s))) return String(v??''); if (isPcs(unit)) return String(Math.round(parseFloat(s))); let t=parseFloat(s).toFixed(3); t=t.replace(/\.?0+$/, ''); return t===''?'0':t; };
const lineBreakdown = l => { if (l.waived) return 'waived'; const q=(fmtQty(l.quantity, l.unit_label)||'0')+' '+(l.unit_label||''); if (l.mult_enabled) return q+' × '+(fmtQty(l.mult_value)||'0')+' '+(l.mult_label||'')+' × '+fmtIDR(l.unit_price); return q+' × '+fmtIDR(l.unit_price); };
function lineAmount(l){ if (l.waived) return 0; const q=parseFloat(l.quantity)||0, p=parseIDR(l.unit_price); if(q<=0) return 0; if(l.mult_enabled){ const m=parseFloat(l.mult_value)||0; if(m<=0) return 0; return Math.round(q*m*p);} return Math.round(q*p); }
function calcAll(sections, dt, dv, pt, pv){
  const flat = sections.flatMap(s=>s.lines);
  const amounts = flat.map(lineAmount);
  const subtotal = amounts.reduce((a,b)=>a+b,0);
  let disc = 0;
  if (dt==='percent') disc = Math.round(subtotal*Math.min(parseFloat(dv)||0,100)/100);
  else if (dt==='fixed') disc = Math.min(parseIDR(dv), subtotal);
  // proportional allocation
  const alloc = new Array(flat.length).fill(0);
  if (disc>0 && subtotal>0){ let assigned=0; const frac=[]; flat.forEach((a,i)=>{ const ex=a*disc/subtotal, f=Math.floor(ex); alloc[i]=f; assigned+=f; frac.push([ex-f,i]); }); let rest=disc-assigned; frac.sort((a,b)=>b[0]-a[0]); for (const [,i] of frac){ if(rest<=0)break; if(amounts[i]-alloc[i]>0){alloc[i]++;rest--;} } }
  let taxTotal=0; const bd={};
  flat.forEach((l,i)=>{ const taxable=amounts[i]-alloc[i]; const r=parseFloat(l.tax_rate)||0; const t=(taxable>0&&r>0)?Math.round(taxable*r/100):0; taxTotal+=t; if(r>0&&taxable>0){ const k=r.toFixed(2); bd[k]=bd[k]||{rate:r,base:0,tax:0}; bd[k].base+=taxable; bd[k].tax+=t; }});
  const total=subtotal-disc+taxTotal;
  let dp=0;
  if(pt==='percent') dp=Math.round(total*Math.min(parseFloat(pv)||0,100)/100);
  else if(pt==='fixed') dp=Math.min(parseIDR(pv),total);
  return {amounts,subtotal,disc,taxTotal,total,dp,remain:total-dp,breakdown:Object.values(bd)};
}
function esc(s){ return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function taxOptions(sel){ let h='<option value="">No tax</option>'; (E.taxes||[]).forEach(t=>{ h+=`<option value="${t.id}" data-rate="${t.rate}" ${String(sel)===String(t.id)?'selected':''}>${esc(t.label)}</option>`;}); return h; }
function applyProduct(si, li, p) {
  const line = E.sections[si].lines[li];
  line.product_id = p.id || null;
  line.product_name = p.name || '';
  if (!line.description) line.description = p.description || p.name;
  line.unit_label = p.unit_label; line.unit_price = p.unit_price;
  line.mult_enabled = p.mult_enabled_default ? 1 : 0;
  line.mult_label = p.mult_label || 'days'; line.mult_value = p.mult_value || 1;
  line.tax_rate_id = p.tax_rate_id || ''; line.tax_rate = parseFloat(p.tax_rate_val || 0);
  // Update this line's card in place. A full render() here would destroy the
  // TomSelect instance that fired onChange and wipe the displayed product name.
  const pick = document.querySelector(`#sections select.prod-pick[data-s="${si}"][data-l="${li}"]`);
  const card = pick ? pick.closest('.rounded-lg') : null;
  if (card) {
    const set = (f, v) => { const el = card.querySelector(`[data-f="${f}"]`); if (el) el.value = v ?? ''; };
    set('description', line.description);
    set('unit_label', line.unit_label);
    set('unit_price', line.unit_price);
    const tax = card.querySelector('[data-f="tax_rate_id"]');
    if (tax) tax.value = String(line.tax_rate_id || '');
    const mc = card.querySelector('[data-f="mult_enabled"]');
    if (mc) mc.checked = !!line.mult_enabled;
    const mctl = card.querySelector('.mult-ctl');
    if (mctl) mctl.style.display = line.mult_enabled ? '' : 'none';
    set('mult_value', fmtQty(line.mult_value ?? 1));
    set('mult_label', line.mult_label || 'days');
  }
  totals();
}
function initProductPicks(scope) {
  if (typeof TomSelect === 'undefined') return;
  scope.querySelectorAll('select.prod-pick').forEach(el => {
    if (el.tomselect) return;
    const si = +el.dataset.s, li = +el.dataset.l;
    const ts = new TomSelect(el, {
      valueField: 'id', labelField: 'name', searchField: ['name', 'description'],
      placeholder: 'Type to search products…',
      maxOptions: 20,
      render: {
        option: function (d, escape) {
          return '<div><span>' + escape(d.name) + '</span><span class="opt-price">' + escape(d.price_text || '') + '</span>' +
            (d.description ? '<div class="text-xs text-slate-500">' + escape(d.description) + '</div>' : '') + '</div>';
        }
      },
      load: function (q, cb) {
        if (!q) return cb();
        fetch('/api/products?s=' + encodeURIComponent(q)).then(r => r.json()).then(rows => {
          cb((rows || []).map(p => ({
            id: p.id, name: p.name, description: p.description || '', unit_label: p.unit_label,
            unit_price: p.unit_price, price_text: fmtIDR(p.unit_price),
            mult_enabled_default: p.mult_enabled_default, mult_label: p.mult_label, mult_value: p.mult_value,
            tax_rate_id: p.tax_rate_id, tax_rate_val: p.tax_rate_val
          })));
        }).catch(() => cb());
      },
      onChange: function (id) {
        if (!id) return;
        const sel = this.options[id];
        // Pre-seeded display stubs carry no pricing — the line already holds
        // those values, so re-applying would blank them. Skip stubs.
        if (sel && sel.unit_price !== undefined) applyProduct(si, li, sel);
      }
    });
    // Restore the current selection so the name survives re-renders and
    // edit-page loads (lines carry product_id + product_name in the model).
    const ln = (E.sections[si] && E.sections[si].lines[li]) || null;
    if (ln && ln.product_id) {
      ts.addOption({id: ln.product_id, name: ln.product_name || ('#' + ln.product_id), description: '', price_text: ''});
      ts.setValue(String(ln.product_id), true);
    }
  });
}
function render(){
  const wrap=document.getElementById('sections'); wrap.innerHTML='';
  E.sections.forEach((sec,si)=>{
    const d=document.createElement('div'); d.className='rounded-xl border-2 border-brand-100 bg-indigo-50/40 p-4 mb-4';
    d.innerHTML=`<div class="grid grid-cols-1 md:grid-cols-3 gap-2"><div class="md:col-span-2"><input class="field" data-s="${si}" data-f="title" value="${esc(sec.title)}" placeholder="Section title *"></div><div class="flex flex-wrap gap-1.5"><button type="button" class="btn btn-default btn-sm" data-collapse="${si}">${sec._collapsed?'▸ Expand':'▾ Collapse'}</button> <button type="button" class="btn btn-default btn-sm" data-addline="${si}">+ Item</button> <button type="button" class="btn btn-danger btn-sm" data-delsec="${si}">Delete</button> <button type="button" class="btn btn-default btn-sm" data-mvsec="${si}|-1">↑</button><button type="button" class="btn btn-default btn-sm" data-mvsec="${si}|1">↓</button></div><div class="md:col-span-3"><input class="field" data-s="${si}" data-f="description" value="${esc(sec.description||'')}" placeholder="Section description (optional)"></div></div><div class="sec-body"${sec._collapsed?' style="display:none"':''}><div class="lines mt-2"></div><div class="text-xs text-slate-500 sec-sub mt-1"></div></div>`;
    const lw=d.querySelector('.lines');
    sec.lines.forEach((l,li)=>{
      const c=document.createElement('div'); c.className='rounded-lg border border-slate-200 bg-white p-3 mb-2';
      c.innerHTML=`
      <div class="mb-2"><select class="prod-pick field" data-s="${si}" data-l="${li}" placeholder="Type to search products, or describe a custom line below…"></select></div>
      <div class="grid grid-cols-2 md:grid-cols-12 gap-2">
        <div class="col-span-2 md:col-span-12"><textarea class="field" rows="2" data-s="${si}" data-l="${li}" data-f="description" placeholder="Description *">${esc(l.description)}</textarea></div>
        <div class="md:col-span-2"><label class="text-xs font-medium text-slate-600">Qty<input class="field mt-0.5" data-s="${si}" data-l="${li}" data-f="quantity" value="${esc(fmtQty(l.quantity ?? 1, l.unit_label))}"></label></div>
        <div class="md:col-span-2"><label class="text-xs font-medium text-slate-600">Unit<input class="field mt-0.5" data-s="${si}" data-l="${li}" data-f="unit_label" value="${esc(l.unit_label||'pcs')}" list="units"></label></div>
        <div class="md:col-span-3"><label class="text-xs font-medium text-slate-600">Unit price (IDR)<input class="field mt-0.5" data-s="${si}" data-l="${li}" data-f="unit_price" value="${esc(l.unit_price??0)}"></label></div>
        <div class="md:col-span-3"><label class="text-xs font-medium text-slate-600">Tax<select class="field mt-0.5" data-s="${si}" data-l="${li}" data-f="tax_rate_id">${taxOptions(l.tax_rate_id)}</select></label></div>
        <div class="md:col-span-2 flex items-end"><button type="button" class="btn btn-danger btn-sm" data-delline="${si}|${li}">Remove</button></div>
        <div class="md:col-span-6"><label class="flex items-center gap-2 text-sm"><input type="checkbox" class="h-4 w-4 accent-brand-600" data-s="${si}" data-l="${li}" data-f="mult_enabled" ${l.mult_enabled?'checked':''}> Use multiplier</label>
          <span class="mult-ctl mt-1 flex gap-2" style="${l.mult_enabled?'':'display:none'}"><input class="field max-w-[7rem]" data-s="${si}" data-l="${li}" data-f="mult_value" value="${esc(fmtQty(l.mult_value ?? 1))}" placeholder="e.g. 1.5"> <input class="field max-w-[9rem]" data-s="${si}" data-l="${li}" data-f="mult_label" value="${esc(l.mult_label||'days')}" placeholder="days"></span></div>
        <div class="md:col-span-6"><label class="flex items-center gap-2 text-sm"><input type="checkbox" class="h-4 w-4 accent-brand-600" data-s="${si}" data-l="${li}" data-f="waived" ${l.waived?'checked':''}> Zero-price (waived)</label>
          <span class="waive-ctl mt-1" style="${l.waived?'':'display:none'}"><select class="field max-w-[10rem]" data-s="${si}" data-l="${li}" data-f="price_mode"><option value="waived">Waived</option><option value="compliment" ${l.price_mode==='compliment'?'selected':''}>Compliment</option><option value="included" ${l.price_mode==='included'?'selected':''}>Included</option></select></span></div>
        <div class="md:col-span-12 text-xs text-slate-500">Line total: <b class="line-amt"></b></div>
      </div>`;
      lw.appendChild(c);
    });
    wrap.appendChild(d);
  });
  bind(); totals();
}
function bind(){
  document.querySelectorAll('#sections [data-f]').forEach(el=>{
    if (el.classList.contains('prod-pick')) return;
    // NOTE: typing must never trigger a full re-render (it would destroy the
    // focused input). Update the model + live totals only; structural changes
    // (add/remove/move) call render() explicitly via their own buttons.
    el.oninput=el.onchange=()=>{
      const s=+el.dataset.s, f=el.dataset.f;
      if(el.dataset.l===undefined){ E.sections[s][f]=el.value; }
      else { const l=E.sections[s].lines[+el.dataset.l];
        if(el.type==='checkbox'){
          l[f]=el.checked?1:0;
          // show/hide the dependent controls inline instead of re-rendering
          const card=el.closest('.rounded-lg');
          if(card){
            const ctl=card.querySelector(f==='mult_enabled' ? '.mult-ctl' : '.waive-ctl');
            if(ctl) ctl.style.display=el.checked?'':'none';
          }
        }
        else l[f]=el.value;
        if(f==='tax_rate_id'){ const o=el.selectedOptions[0]; l.tax_rate=o?parseFloat(o.dataset.rate||0):0; }
      }
      totals();
    };
  });
  initProductPicks(document.getElementById('sections'));
  document.querySelectorAll('[data-collapse]').forEach(b=>b.onclick=()=>{const s=+b.dataset.collapse; E.sections[s]._collapsed=!E.sections[s]._collapsed; render();});
  document.querySelectorAll('[data-addline]').forEach(b=>b.onclick=()=>{E.sections[+b.dataset.addline].lines.push({description:'',quantity:1,unit_label:'pcs',unit_price:0,mult_enabled:0,mult_label:'days',mult_value:1,tax_rate_id:'',tax_rate:0,waived:0,price_mode:'normal'});render();});
  document.querySelectorAll('[data-delline]').forEach(b=>b.onclick=()=>{const [s,l]=b.dataset.delline.split('|').map(Number); if(E.sections[s].lines.length<=1&&E.sections.length<=1){toast('At least one item required','danger');return;} E.sections[s].lines.splice(l,1); if(!E.sections[s].lines.length)E.sections.splice(s,1); render();});
  document.querySelectorAll('[data-delsec]').forEach(b=>b.onclick=()=>{const s=+b.dataset.delsec; if(E.sections.length<=1){toast('At least one section required','danger');return;} confirmDialog('Delete section and its items?').then(()=>{E.sections.splice(s,1);render();}).catch(()=>{});});
  document.querySelectorAll('[data-mvsec]').forEach(b=>b.onclick=()=>{const [s,d]=b.dataset.mvsec.split('|').map(Number); const t=s+d; if(t<0||t>=E.sections.length)return; [E.sections[s],E.sections[t]]=[E.sections[t],E.sections[s]]; render();});
  ['f-dt','f-dv','f-pt','f-pv'].forEach(id=>{const el=document.getElementById(id); if(el){el.oninput=totals;}});
  document.querySelectorAll('.bank-cb').forEach(c=>c.onchange=totals);
}
function totals(){
  const dt=document.getElementById('f-dt').value, dv=document.getElementById('f-dv').value, pt=document.getElementById('f-pt').value, pv=document.getElementById('f-pv').value;
  const r=calcAll(E.sections,dt,dv,pt,pv);
  let idx=0; const secSubs=E.sections.map(sec=>{let s=0; sec.lines.forEach(()=>{s+=r.amounts[idx++];}); return s;});
  const cards=document.getElementById('sections').children;
  [...cards].forEach((el,i)=>{ const sub=el.querySelector('.sec-sub'); if(sub) sub.textContent='Section subtotal: '+fmtIDR(secSubs[i]); });
  const flat=r.amounts; const flatLines=E.sections.flatMap(s=>s.lines); let k=0;
  document.querySelectorAll('#sections .line-amt').forEach(el=>{ const l=flatLines[k]; el.textContent=fmtIDR(flat[k])+' ('+lineBreakdown(l||{})+')'; k++; });
  let h=`<table class="w-full"><tr><td class="py-1">Subtotal</td><td class="py-1 text-right">${fmtIDR(r.subtotal)}</td></tr><tr><td class="py-1">Discount</td><td class="py-1 text-right">− ${fmtIDR(r.disc)}</td></tr>`;
  r.breakdown.forEach(b=>{h+=`<tr><td class="py-1">Tax ${b.rate}%</td><td class="py-1 text-right">${fmtIDR(b.tax)}</td></tr>`;});
  h+=`<tr class="font-semibold"><td class="py-1">Total</td><td class="py-1 text-right">${fmtIDR(r.total)}</td></tr><tr><td class="py-1">Requested DP</td><td class="py-1 text-right">${fmtIDR(r.dp)}</td></tr><tr><td class="py-1">Remaining after DP</td><td class="py-1 text-right">${fmtIDR(r.remain)}</td></tr></table>`;
  document.getElementById('totals').innerHTML=h;
  const banks=[...document.querySelectorAll('.bank-cb:checked')].map(c=>c.parentElement.textContent.trim());
  document.getElementById('bank-preview').textContent=banks.length?('Showing: '+banks.join(' | ')):'No bank accounts will be displayed.';
}
document.getElementById('add-section').onclick=()=>{E.sections.push({title:'Section '+(E.sections.length+1),description:'',lines:[{description:'',quantity:1,unit_label:'pcs',unit_price:0,mult_enabled:0,mult_label:'days',mult_value:1,tax_rate_id:'',tax_rate:0,waived:0,price_mode:'normal'}]});render();};
document.getElementById('doc-form').onsubmit=function(ev){
  // serialize sections into hidden inputs (nested PHP arrays)
  E.sections.forEach((sec,si)=>{
    [['title',sec.title],['description',sec.description||'']].forEach(([f,v])=>{ const h=document.createElement('input'); h.type='hidden'; h.name=`sections[${si}][${f}]`; h.value=v; this.appendChild(h); });
    sec.lines.forEach((l,li)=>{
      // Send canonical decimals (JS parseQty: dot = decimal point). Without
      // this, DB-format strings like "1.000" round-trip back ambiguously.
      const vals={description:l.description,quantity:String(parseQty(l.quantity)),unit_label:l.unit_label,unit_price:l.unit_price,mult_enabled:l.mult_enabled?1:'',mult_label:l.mult_label||'',mult_value:l.mult_value==null||l.mult_value===''?'':String(parseQty(l.mult_value)),tax_rate_id:l.tax_rate_id||'',waived:l.waived?1:'',price_mode:l.price_mode||'normal',product_id:l.product_id||''};
      Object.entries(vals).forEach(([f,v])=>{ const h=document.createElement('input'); h.type='hidden'; h.name=`sections[${si}][lines][${li}][${f}]`; h.value=v??''; this.appendChild(h); });
    });
  });
};
document.getElementById('nc-save').onclick=()=>{
  fetch('/customers',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({name:document.getElementById('nc-name').value,email:document.getElementById('nc-email').value,phone:document.getElementById('nc-phone').value,billing_address:document.getElementById('nc-addr').value,csrf:document.querySelector('[name=csrf]').value,ajax:'1'})}).then(r=>r.json()).then(()=>location.reload());
};
render();
