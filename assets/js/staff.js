/* RAR Woo Stock & Order — staff app v1.2.0 */
(() => {
'use strict';

const C = window.RARWSO || {};
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
const sum = (arr, f) => arr.reduce((s, x) => s + f(x), 0);
const BN = '০১২৩৪৫৬৭৮৯';
const toBn = n => String(n).replace(/\d/g, d => BN[d]);
const fromBn = s => String(s ?? '').replace(/[০-৯]/g, c => BN.indexOf(c));
const DAY = 86400000;
const MANAGER = !!C.isManager;
const THRESHOLD = Number(C.threshold || 10);

/* ---------------- numbers & money ---------------- */
function fmtNum(value, decimals) {
    const dec = Math.max(0, Number(decimals ?? C.decimals ?? 0));
    const n = Number(value || 0), sign = n < 0 ? '-' : '';
    const parts = Math.abs(n).toFixed(dec).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, String(C.thousandSep ?? ','));
    return sign + parts[0] + (dec ? String(C.decimalSep ?? '.') + parts[1] : '');
}
const qtyFmt = q => fmtNum(q, Number.isInteger(Number(q)) ? 0 : 2);
function money(value) {
    const amount = fmtNum(value), sym = String(C.currency || '');
    switch (String(C.currencyPosition || 'left')) {
        case 'right': return amount + sym;
        case 'left_space': return sym + ' ' + amount;
        case 'right_space': return amount + ' ' + sym;
        default: return sym + amount;
    }
}
function moneyShort(v) {
    const sym = String(C.currency || ''), n = Number(v || 0);
    if (n >= 1e7) return sym + (n / 1e7).toFixed(2) + ' Cr';
    if (n >= 1e5) return sym + (n / 1e5).toFixed(n >= 1e6 ? 1 : 2) + 'L';
    if (n >= 1e3) return sym + (n / 1e3).toFixed(n >= 1e4 ? 0 : 1) + 'k';
    return sym + Math.round(n);
}
const moneyCard = v => Number(v) >= 1e6 ? moneyShort(v) : money(v);
function pct(cur, prev) { return prev ? Math.round((cur - prev) / prev * 100) : null; }
function delta(cur, prev, goodUp = true) {
    const p = pct(cur, prev);
    if (p === null) return '';
    const cls = p === 0 ? 'flat' : ((p > 0) === goodUp ? 'good' : 'bad');
    return `<span class="d ${cls}">${p > 0 ? '▲' : p < 0 ? '▼' : '•'} ${Math.abs(p)}%</span> · `;
}

/* amount in words, Bangladeshi lakh / crore system */
const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
const w2 = n => n < 20 ? ONES[n] : TENS[Math.floor(n / 10)] + (n % 10 ? ' ' + ONES[n % 10] : '');
const w3 = n => [Math.floor(n / 100) ? ONES[Math.floor(n / 100)] + ' Hundred' : '', n % 100 ? w2(n % 100) : ''].filter(Boolean).join(' ');
function wordsIN(n) {
    if (n >= 1e7) return [wordsIN(Math.floor(n / 1e7)) + ' Crore', n % 1e7 ? wordsIN(n % 1e7) : ''].filter(Boolean).join(' ');
    const lk = Math.floor(n / 1e5), th = Math.floor(n % 1e5 / 1000), r = n % 1000, out = [];
    if (lk) out.push(w2(lk) + ' Lakh'); if (th) out.push(w2(th) + ' Thousand'); if (r) out.push(w3(r));
    return out.join(' ');
}
function inWords(v) {
    const n = Math.max(0, Number(v || 0)), whole = Math.floor(n + 1e-9), paisa = Math.round((n - whole) * 100);
    const base = whole ? wordsIN(whole) : 'Zero';
    return `Taka ${base}${paisa ? ' and ' + w2(paisa) + ' Paisa' : ''} Only`;
}

/* ---------------- time in the store's timezone ---------------- */
const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const WDL = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const WDS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
let ZF = null;
try { if (C.timezone && !/^[+-]/.test(C.timezone)) ZF = new Intl.DateTimeFormat('en-US', { timeZone:C.timezone, year:'numeric', month:'numeric', day:'numeric', hour:'numeric', minute:'numeric', second:'numeric', weekday:'short', hourCycle:'h23' }); } catch (e) { ZF = null; }
function zp(ms) {
    if (ZF) {
        const o = {}; ZF.formatToParts(new Date(ms)).forEach(p => { o[p.type] = p.value; });
        return { y:+o.year, m:+o.month - 1, d:+o.day, h:(+o.hour) % 24, mi:+o.minute, s:+o.second, wd:WDS.indexOf(o.weekday) };
    }
    const d = new Date(ms + Number(C.tzOffset || 0) * 1000);
    return { y:d.getUTCFullYear(), m:d.getUTCMonth(), d:d.getUTCDate(), h:d.getUTCHours(), mi:d.getUTCMinutes(), s:d.getUTCSeconds(), wd:d.getUTCDay() };
}
const pad = n => String(n).padStart(2, '0');
const dkey = ms => { const p = zp(ms); return `${p.y}-${p.m}-${p.d}`; };
function hm(ms, sec) { const p = zp(ms); const h = p.h % 12 || 12; return `${pad(h)}:${pad(p.mi)}${sec ? ':' + pad(p.s) : ''} ${p.h >= 12 ? 'pm' : 'am'}`; }
function dmy(ms) { const p = zp(ms); return `${MON[p.m]} ${p.d}, ${p.y}`; }
function md(ms) { const p = zp(ms); return `${MON[p.m]} ${p.d}`; }
function when(ms) { const now = Date.now(); if (dkey(ms) === dkey(now)) return 'Today ' + hm(ms); if (dkey(ms) === dkey(now - DAY)) return 'Yesterday ' + hm(ms); return md(ms) + ', ' + hm(ms); }
function ago(ms) { const s = (Date.now() - ms) / 1000; if (s < 60) return 'just now'; if (s < 3600) return Math.floor(s / 60) + ' min ago'; if (s < 86400) return Math.floor(s / 3600) + ' h ago'; const d = Math.floor(s / 86400); return d === 1 ? '1 day ago' : d + ' days ago'; }
function waitFor(ms) { const h = (Date.now() - ms) / 3600000; if (h < 1) return Math.max(1, Math.round(h * 60)) + ' min'; if (h < 24) return Math.floor(h) + ' h'; return Math.floor(h / 24) + ' d ' + Math.floor(h % 24) + ' h'; }
const ymdLabel = s => { const [y, m, d] = String(s).split('-').map(Number); return `${MON[m - 1]} ${d}`; };
const ymdWeekday = s => { const [y, m, d] = String(s).split('-').map(Number); return WDS[new Date(Date.UTC(y, m - 1, d)).getUTCDay()]; };

/* ---------------- icons ---------------- */
const sv = p => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${p}</svg>`;
const ICON = {
    orders: sv('<path d="M5 8h14l-1.2 12H6.2L5 8z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/>'),
    taka: `<span class="tk">${esc(C.currency || '$')}</span>`,
    check: sv('<circle cx="12" cy="12" r="9"/><path d="M8 12.3l2.7 2.7L16 9.6"/>'),
    ret: sv('<path d="M9 14L4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>'),
    boxes: sv('<path d="M3.5 7.5L12 3l8.5 4.5L12 12 3.5 7.5z"/><path d="M3.5 7.5v9L12 21l8.5-4.5v-9"/><path d="M12 12v9"/>'),
    pulse: sv('<path d="M3 12h4l2.5-6 5 12 2.5-6H21"/>'),
    ban: sv('<circle cx="12" cy="12" r="9"/><path d="M5.7 5.7l12.6 12.6"/>'),
    sliders: sv('<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>'),
    plus: sv('<path d="M12 5v14M5 12h14"/>'),
    minus: sv('<path d="M5 12h14"/>'),
    list: sv('<path d="M9 6h11M9 12h11M9 18h11"/><path d="M4 6h.01M4 12h.01M4 18h.01"/>'),
    truck: sv('<path d="M2.5 6.5h11v10h-11z"/><path d="M13.5 10h4l3 3.2v3.3h-7"/><circle cx="6.5" cy="17.5" r="2"/><circle cx="17" cy="17.5" r="2"/>'),
    clock: sv('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 2"/>'),
    arrow: sv('<path d="M7 17L17 7M9 7h8v8"/>'),
    x: sv('<path d="M6 6l12 12M18 6L6 18"/>'),
    back: sv('<path d="M15 18l-6-6 6-6"/>'),
    search: sv('<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>'),
    chev: sv('<path d="M9 6l6 6-6 6"/>'),
    trash: sv('<path d="M4 7h16M9.5 7V4.5h5V7M6.5 7l.9 13h9.2l.9-13"/>'),
    ok: sv('<path d="M5 12.5l4.5 4.5L19 7.5"/>'),
    share: sv('<path d="M12 3v12"/><path d="M7.5 7.5L12 3l4.5 4.5"/><path d="M5 12v7h14v-7"/>'),
    chart: sv('<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>'),
};

/* ---------------- statuses & stock levels ---------------- */
const KNOWN_ST = ['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed'];
const stClass = s => 'st-' + (KNOWN_ST.includes(s) ? s : 'other');
const stLabel = s => (C.statuses && C.statuses[s]) || s;
const STATUSES = Object.keys(C.statuses || {});
const LIVE = new Set(C.liveStatuses || ['pending', 'processing', 'on-hold']);
const isReturn = s => s === 'cancelled' || s === 'refunded' || s.includes('return');
const isVoid = s => ['cancelled', 'refunded', 'failed'].includes(s) || s.includes('return');
const LVLABEL = { ok:`${THRESHOLD}+ In stock`, low:`Low 1–${THRESHOLD}`, out:'Stock out', untracked:'Not tracked' };
const LVCHIP = { all:'All', live:'All live', ok:`${THRESHOLD}+ In stock`, low:`Low 1–${THRESHOLD}`, out:'Stock out', untracked:'Not tracked' };
const levelOf = (p, qty) => {
    if (qty === '' || qty === null || qty === undefined) return p.level === 'out' ? 'out' : 'untracked';
    const q = Number(qty);
    return q <= 0 ? 'out' : q <= THRESHOLD ? 'low' : 'ok';
};
$('#rar-legend') && ($('#rar-legend').innerHTML = `<span><i class="dot lv-ok"></i>${LVLABEL.ok}</span><span><i class="dot lv-low"></i>${LVLABEL.low}</span><span><i class="dot lv-out"></i>Stock out 0</span>`);

/* ---------------- Bangladesh districts → towns ---------------- */
const BD = {
    Dhaka:['Dhaka','Adabor,Badda,Banani,Bangshal,Bashundhara,Bimanbandar,Cantonment,Chawkbazar,Dakshinkhan,Darus Salam,Demra,Dhamrai,Dhanmondi,Dohar,Gendaria,Gulshan,Hazaribagh,Jatrabari,Kadamtali,Kafrul,Kalabagan,Kamrangirchar,Keraniganj,Khilgaon,Khilkhet,Kotwali,Lalbagh,Mirpur,Mohammadpur,Motijheel,Mugda,Nawabganj,New Market,Pallabi,Paltan,Ramna,Rampura,Sabujbagh,Savar,Shah Ali,Shahbagh,Sher-e-Bangla Nagar,Shyampur,Sutrapur,Tejgaon,Turag,Uttara,Uttarkhan,Vatara,Wari'],
    Faridpur:['Dhaka','Alfadanga,Bhanga,Boalmari,Charbhadrasan,Faridpur Sadar,Madhukhali,Nagarkanda,Sadarpur,Saltha'],
    Gazipur:['Dhaka','Gazipur Sadar,Kaliakair,Kaliganj,Kapasia,Sreepur,Tongi'],
    Gopalganj:['Dhaka','Gopalganj Sadar,Kashiani,Kotalipara,Muksudpur,Tungipara'],
    Kishoreganj:['Dhaka','Austagram,Bajitpur,Bhairab,Hossainpur,Itna,Karimganj,Katiadi,Kishoreganj Sadar,Kuliarchar,Mithamain,Nikli,Pakundia,Tarail'],
    Madaripur:['Dhaka','Dasar,Kalkini,Madaripur Sadar,Rajoir,Shibchar'],
    Manikganj:['Dhaka','Daulatpur,Ghior,Harirampur,Manikganj Sadar,Saturia,Shibalaya,Singair'],
    Munshiganj:['Dhaka','Gazaria,Lohajang,Munshiganj Sadar,Sirajdikhan,Sreenagar,Tongibari'],
    Narayanganj:['Dhaka','Araihazar,Bandar,Fatullah,Narayanganj Sadar,Rupganj,Siddhirganj,Sonargaon'],
    Narsingdi:['Dhaka','Belabo,Monohardi,Narsingdi Sadar,Palash,Raipura,Shibpur'],
    Rajbari:['Dhaka','Baliakandi,Goalanda,Kalukhali,Pangsha,Rajbari Sadar'],
    Shariatpur:['Dhaka','Bhedarganj,Damudya,Gosairhat,Naria,Shariatpur Sadar,Zajira'],
    Tangail:['Dhaka','Basail,Bhuapur,Delduar,Dhanbari,Ghatail,Gopalpur,Kalihati,Madhupur,Mirzapur,Nagarpur,Sakhipur,Tangail Sadar'],
    Jamalpur:['Mymensingh','Bakshiganj,Dewanganj,Islampur,Jamalpur Sadar,Madarganj,Melandaha,Sarishabari'],
    Mymensingh:['Mymensingh','Bhaluka,Dhobaura,Fulbaria,Gaffargaon,Gauripur,Haluaghat,Ishwarganj,Muktagachha,Mymensingh Sadar,Nandail,Phulpur,Tarakanda,Trishal'],
    Netrokona:['Mymensingh','Atpara,Barhatta,Durgapur,Kalmakanda,Kendua,Khaliajuri,Madan,Mohanganj,Netrokona Sadar,Purbadhala'],
    Sherpur:['Mymensingh','Jhenaigati,Nakla,Nalitabari,Sherpur Sadar,Sreebardi'],
    Bandarban:['Chattogram','Ali Kadam,Bandarban Sadar,Lama,Naikhongchhari,Rowangchhari,Ruma,Thanchi'],
    Brahmanbaria:['Chattogram','Akhaura,Ashuganj,Bancharampur,Bijoynagar,Brahmanbaria Sadar,Kasba,Nabinagar,Nasirnagar,Sarail'],
    Chandpur:['Chattogram','Chandpur Sadar,Faridganj,Haimchar,Haziganj,Kachua,Matlab Dakshin,Matlab Uttar,Shahrasti'],
    Chattogram:['Chattogram','Agrabad,Akbar Shah,Anwara,Bakalia,Banshkhali,Bayazid,Boalkhali,Chandanaish,Chandgaon,Chawkbazar,Double Mooring,EPZ,Fatikchhari,Halishahar,Hathazari,Karnaphuli,Khulshi,Kotwali,Lohagara,Mirsharai,Pahartali,Panchlaish,Patenga,Patiya,Rangunia,Raozan,Sandwip,Satkania,Sitakunda'],
    "Cox's Bazar":['Chattogram',"Chakaria,Cox's Bazar Sadar,Eidgaon,Kutubdia,Maheshkhali,Pekua,Ramu,Teknaf,Ukhia"],
    Cumilla:['Chattogram','Barura,Brahmanpara,Burichang,Chandina,Chauddagram,Cumilla Adarsha Sadar,Cumilla Sadar Dakshin,Daudkandi,Debidwar,Homna,Laksam,Lalmai,Meghna,Monohorganj,Muradnagar,Nangalkot,Titas'],
    Feni:['Chattogram','Chhagalnaiya,Daganbhuiyan,Feni Sadar,Fulgazi,Parshuram,Sonagazi'],
    Khagrachhari:['Chattogram','Dighinala,Guimara,Khagrachhari Sadar,Lakshmichhari,Mahalchhari,Manikchhari,Matiranga,Panchhari,Ramgarh'],
    Lakshmipur:['Chattogram','Kamalnagar,Lakshmipur Sadar,Raipur,Ramganj,Ramgati'],
    Noakhali:['Chattogram','Begumganj,Chatkhil,Companiganj,Hatiya,Kabirhat,Noakhali Sadar,Senbagh,Sonaimuri,Subarnachar'],
    Rangamati:['Chattogram','Baghaichhari,Barkal,Belaichhari,Juraichhari,Kaptai,Kawkhali,Langadu,Naniarchar,Rajasthali,Rangamati Sadar'],
    Bogura:['Rajshahi','Adamdighi,Bogura Sadar,Dhunat,Dhupchanchia,Gabtali,Kahaloo,Nandigram,Sariakandi,Shajahanpur,Sherpur,Shibganj,Sonatala'],
    Chapainawabganj:['Rajshahi','Bholahat,Chapainawabganj Sadar,Gomastapur,Nachole,Shibganj'],
    Joypurhat:['Rajshahi','Akkelpur,Joypurhat Sadar,Kalai,Khetlal,Panchbibi'],
    Naogaon:['Rajshahi','Atrai,Badalgachhi,Dhamoirhat,Manda,Mohadevpur,Naogaon Sadar,Niamatpur,Patnitala,Porsha,Raninagar,Sapahar'],
    Natore:['Rajshahi','Bagatipara,Baraigram,Gurudaspur,Lalpur,Naldanga,Natore Sadar,Singra'],
    Pabna:['Rajshahi','Atgharia,Bera,Bhangura,Chatmohar,Faridpur,Ishwardi,Pabna Sadar,Santhia,Sujanagar'],
    Rajshahi:['Rajshahi','Bagha,Bagmara,Boalia,Charghat,Durgapur,Godagari,Mohanpur,Motihar,Paba,Puthia,Rajpara,Shah Makhdum,Tanore'],
    Sirajganj:['Rajshahi','Belkuchi,Chauhali,Kamarkhanda,Kazipur,Raiganj,Shahjadpur,Sirajganj Sadar,Tarash,Ullahpara'],
    Bagerhat:['Khulna','Bagerhat Sadar,Chitalmari,Fakirhat,Kachua,Mollahat,Mongla,Morrelganj,Rampal,Sarankhola'],
    Chuadanga:['Khulna','Alamdanga,Chuadanga Sadar,Damurhuda,Jibannagar'],
    Jashore:['Khulna','Abhaynagar,Bagherpara,Chaugachha,Jashore Sadar,Jhikargachha,Keshabpur,Manirampur,Sharsha'],
    Jhenaidah:['Khulna','Harinakunda,Jhenaidah Sadar,Kaliganj,Kotchandpur,Maheshpur,Shailkupa'],
    Khulna:['Khulna','Batiaghata,Dacope,Daulatpur,Dighalia,Dumuria,Khalishpur,Khan Jahan Ali,Kotwali,Koyra,Paikgachha,Phultala,Rupsha,Sonadanga,Terokhada'],
    Kushtia:['Khulna','Bheramara,Daulatpur,Khoksa,Kumarkhali,Kushtia Sadar,Mirpur'],
    Magura:['Khulna','Magura Sadar,Mohammadpur,Shalikha,Sreepur'],
    Meherpur:['Khulna','Gangni,Meherpur Sadar,Mujibnagar'],
    Narail:['Khulna','Kalia,Lohagara,Narail Sadar'],
    Satkhira:['Khulna','Assasuni,Debhata,Kalaroa,Kaliganj,Satkhira Sadar,Shyamnagar,Tala'],
    Barguna:['Barishal','Amtali,Bamna,Barguna Sadar,Betagi,Patharghata,Taltali'],
    Barishal:['Barishal','Agailjhara,Babuganj,Bakerganj,Banaripara,Barishal Sadar,Gournadi,Hizla,Mehendiganj,Muladi,Wazirpur'],
    Bhola:['Barishal','Bhola Sadar,Burhanuddin,Char Fasson,Daulatkhan,Lalmohan,Manpura,Tazumuddin'],
    Jhalokati:['Barishal','Jhalokati Sadar,Kathalia,Nalchity,Rajapur'],
    Patuakhali:['Barishal','Bauphal,Dashmina,Dumki,Galachipa,Kalapara,Mirzaganj,Patuakhali Sadar,Rangabali'],
    Pirojpur:['Barishal','Bhandaria,Indurkani,Kawkhali,Mathbaria,Nazirpur,Nesarabad,Pirojpur Sadar'],
    Habiganj:['Sylhet','Ajmiriganj,Bahubal,Baniyachong,Chunarughat,Habiganj Sadar,Lakhai,Madhabpur,Nabiganj,Shayestaganj'],
    Moulvibazar:['Sylhet','Barlekha,Juri,Kamalganj,Kulaura,Moulvibazar Sadar,Rajnagar,Sreemangal'],
    Sunamganj:['Sylhet','Bishwambarpur,Chhatak,Derai,Dharamapasha,Dowarabazar,Jagannathpur,Jamalganj,Madhyanagar,Shantiganj,Sullah,Sunamganj Sadar,Tahirpur'],
    Sylhet:['Sylhet','Balaganj,Beanibazar,Bishwanath,Companiganj,Dakshin Surma,Fenchuganj,Golapganj,Gowainghat,Jaintiapur,Kanaighat,Osmani Nagar,Sylhet Sadar,Zakiganj'],
    Dinajpur:['Rangpur','Biral,Birampur,Birganj,Bochaganj,Chirirbandar,Dinajpur Sadar,Ghoraghat,Hakimpur,Kaharole,Khansama,Nawabganj,Parbatipur,Phulbari'],
    Gaibandha:['Rangpur','Gaibandha Sadar,Gobindaganj,Palashbari,Phulchhari,Sadullapur,Saghata,Sundarganj'],
    Kurigram:['Rangpur','Bhurungamari,Char Rajibpur,Chilmari,Kurigram Sadar,Nageshwari,Phulbari,Rajarhat,Raomari,Ulipur'],
    Lalmonirhat:['Rangpur','Aditmari,Hatibandha,Kaliganj,Lalmonirhat Sadar,Patgram'],
    Nilphamari:['Rangpur','Dimla,Domar,Jaldhaka,Kishoreganj,Nilphamari Sadar,Saidpur'],
    Panchagarh:['Rangpur','Atwari,Boda,Debiganj,Panchagarh Sadar,Tetulia'],
    Rangpur:['Rangpur','Badarganj,Gangachhara,Kaunia,Mithapukur,Pirgachha,Pirganj,Rangpur Sadar,Taraganj'],
    Thakurgaon:['Rangpur','Baliadangi,Haripur,Pirganj,Ranisankail,Thakurgaon Sadar'],
};
const norm = s => String(s || '').toLowerCase().replace(/[^a-z]/g, '');
const ALIAS = { nawabganj:'chapainawabganj', chapainawabganj:'chapainawabganj', netrakona:'netrokona', chittagong:'chattogram', comilla:'cumilla', barisal:'barishal', bogra:'bogura', jessore:'jashore', jhalakathi:'jhalokati', jhalokathi:'jhalokati', maulvibazar:'moulvibazar' };
const BDN = {}; Object.keys(BD).forEach(k => { BDN[norm(k)] = BD[k]; });
const bdInfo = name => { const n = norm(name); return BDN[ALIAS[n] || n] || null; };
const DISTRICTS = (Array.isArray(C.districtList) ? C.districtList : (C.districts || []).map(n => ({ code:'', name:n })))
    .map(d => ({ code:d.code, name:String(d.name).trim(), div:(bdInfo(d.name) || [''])[0] }))
    .sort((a, b) => a.name.localeCompare(b.name));
const townsFor = district => { const i = bdInfo(district); return i ? i[1].split(',') : []; };
const isDhaka = district => norm(district) === 'dhaka';
function shipFor(district) {
    if (!district) return Number(C.shipping || 0);
    const v = isDhaka(district) ? C.shippingDhaka : C.shippingOutside;
    return v === null || v === undefined ? Number(C.shipping || 0) : Number(v);
}
const shipLabel = district => isDhaka(district) ? 'Inside Dhaka' : 'Outside Dhaka';

/* ---------------- server ---------------- */
function needLogin() {
    toast('Your login has ended. Please sign in again.', { error:true, long:true, action:'Sign in', fn:() => { location.href = C.loginUrl || C.staffUrl || location.href; } });
}
let refreshing = null;
async function refreshNonce() {
    if (!refreshing) {
        refreshing = (async () => {
            const body = new URLSearchParams({ action:'rar_wso_refresh' });
            const r = await fetch(C.ajaxUrl, { method:'POST', credentials:'same-origin', cache:'no-store', headers:{ 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8' }, body });
            let j = null; try { j = await r.json(); } catch (_) {}
            if (j && j.success && j.data && j.data.nonce) { C.nonce = j.data.nonce; C.nonceAt = Date.now(); return true; }
            return false;
        })().catch(() => false).finally(() => { setTimeout(() => { refreshing = null; }, 0); });
    }
    return refreshing;
}
async function api(action, data = {}, retried = false) {
    const body = new URLSearchParams({ action:'rar_wso_' + action, nonce:C.nonce });
    Object.entries(data).forEach(([k, v]) => { if (v !== undefined && v !== null) body.append(k, typeof v === 'object' ? JSON.stringify(v) : String(v)); });
    let response;
    try {
        response = await fetch(C.ajaxUrl, { method:'POST', credentials:'same-origin', cache:'no-store', headers:{ 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8' }, body });
    } catch (e) {
        throw new Error(navigator.onLine === false ? 'No internet connection. Check Wi-Fi / mobile data and try again.' : 'Could not reach the server. Try again.');
    }
    let json = null, text = '';
    try { text = await response.text(); json = JSON.parse(text); } catch (_) { json = null; }
    const expired = response.status === 403 && (text.trim() === '-1' || (json && json.data && json.data.nonce));
    const loggedOut = response.status === 401 || (response.status === 400 && text.trim() === '0');
    if ((expired || loggedOut) && !retried) {
        // Nonce went stale while the app sat in the background: get a fresh one and retry once.
        if (await refreshNonce()) return api(action, data, true);
        needLogin();
        throw new Error('Your login has ended. Please sign in again.');
    }
    if (loggedOut) { needLogin(); throw new Error('Your login has ended. Please sign in again.'); }
    if (!json) throw new Error('Invalid server response (' + response.status + '). Try again.');
    if (!response.ok || !json.success) throw new Error(json?.data?.message || 'Request failed.');
    return json.data;
}

/* ---------------- toasts ---------------- */
const TOASTS = $('#rar-toasts');
function toast(html, opt = {}) {
    if (!TOASTS) return;
    const t = document.createElement('div');
    t.className = 'toast' + (opt.error ? ' err' : '');
    t.innerHTML = `<span>${html}</span>`;
    if (opt.action) {
        const b = document.createElement('button'); b.type = 'button'; b.textContent = opt.action;
        b.addEventListener('click', () => { t.remove(); opt.fn(); });
        t.appendChild(b);
    }
    TOASTS.appendChild(t);
    while (TOASTS.children.length > 3) TOASTS.firstChild.remove();
    setTimeout(() => { t.classList.add('out'); setTimeout(() => t.remove(), 300); }, opt.action || opt.long || opt.error ? 6500 : 3400);
}
const fail = e => toast(esc(e && e.message ? e.message : String(e)), { error:true });

/* ================================================================
   Dashboard
   ================================================================ */
const S = { period:'today', days:30, stats:null, report:null, loading:false };
const canOrders = !!C.canViewOrders;

function card({ key, hue, icon, label, num, sub, extra = '', wide = false, click = true }) {
    const tag = click ? 'button' : 'div';
    return `<${tag} ${click ? 'type="button" ' : ''}class="card${wide ? ' wide' : ''}" style="--c:var(${hue})"${click ? ` data-open="${key}"` : ''}>
        <span class="c-top"><span class="c-ico">${ICON[icon]}</span>${click ? `<span class="c-go" aria-hidden="true">${ICON.arrow}</span>` : ''}</span>
        <span class="c-lbl">${label}</span><span class="c-num">${num}</span>${extra}<span class="c-sub">${sub}</span></${tag}>`;
}
const PLABEL = {
    today:{ orders:"Today's Orders", sales:"Today's Sales", done:'Completed Orders', ret:'Returned / Cancelled' },
    '7d':{ orders:'Orders · 7 days', sales:'Sales · 7 days', done:'Completed · 7 days', ret:'Returned / Cancelled · 7 days' },
    month:{ orders:'Orders · this month', sales:'Sales · this month', done:'Completed · this month', ret:'Returned / Cancelled · this month' },
};
function renderKPIs() {
    const box = $('#rar-kpis'); if (!box || !S.stats) return;
    const p = S.stats.period, c = p.cur, pv = p.prev, L = PLABEL[S.period];
    box.innerHTML = [
        card({ key:'k-orders', hue:'--c-orders', icon:'orders', label:L.orders, num:fmtNum(c.orders, 0), sub:`${delta(c.orders, pv.orders)}${esc(p.vs)}: ${fmtNum(pv.orders, 0)}`, click:canOrders }),
        card({ key:'k-sales', hue:'--c-sales', icon:'taka', label:L.sales, num:esc(moneyCard(c.sales)), sub:`${delta(c.sales, pv.sales)}avg order ${esc(money(c.avg))}`, click:canOrders }),
        card({ key:'k-done', hue:'--c-done', icon:'check', label:L.done, num:fmtNum(c.completed, 0), sub:`${c.orders ? Math.round(c.completed / c.orders * 100) : 0}% of ${S.period === 'today' ? "today's" : 'these'} orders`, click:canOrders }),
        card({ key:'k-ret', hue:'--c-return', icon:'ret', label:L.ret, num:fmtNum(c.cancelled + c.returned, 0), sub:`${c.cancelled} cancelled · ${c.returned} returned/refunded`, click:canOrders }),
    ].join('');
}
const shortName = s => String(s || '').length > 34 ? String(s).slice(0, 32) + '…' : String(s || '');
function renderStockCards() {
    const box = $('#rar-stock-cards'); if (!box || !S.stats) return;
    const s = S.stats.stock, seg = (k, v) => v ? `<i class="${k}" style="flex:${v}"></i>` : '';
    const outNames = (S.stats.out_names || []).map(x => shortName(x.name));
    box.innerHTML = [
        card({ key:'s-all', hue:'--c-allstock', icon:'boxes', label:'All Stock', wide:true, num:`${fmtNum(s.all, 0)}<small>products</small>`,
            extra:`<span class="dist" aria-hidden="true">${seg('ok', s.ok)}${seg('low', s.low)}${seg('out', s.out)}${seg('untracked', s.untracked)}</span>
            <span class="dist-lg"><span><i class="dot lv-ok"></i><b>${s.ok}</b> ${THRESHOLD}+</span><span><i class="dot lv-low"></i><b>${s.low}</b> low</span><span><i class="dot lv-out"></i><b>${s.out}</b> out</span>${s.untracked ? `<span><i class="dot lv-untracked"></i><b>${s.untracked}</b> not tracked</span>` : ''}</span>`,
            sub:`${qtyFmt(s.units)} units in hand · stock value ${esc(moneyShort(s.value))}` }),
        card({ key:'s-live', hue:'--c-live', icon:'pulse', label:'Available / Live Stock', num:`${fmtNum(s.live, 0)}<small>products</small>`,
            sub:`<span class="lg"><i class="dot lv-ok"></i>${s.ok} healthy</span><span class="lg"><i class="dot lv-low"></i>${s.low} low</span>` }),
        card({ key:'s-out', hue:'--c-out', icon:'ban', label:'Out of Stock', num:`${fmtNum(s.out, 0)}<small>products</small>`,
            sub: s.out ? esc(outNames.slice(0, 2).join(', ')) + (s.out > 2 ? ` +${s.out - 2} more` : '') : 'সব product-এ stock আছে' }),
    ].join('');
}
function renderMgrCards() {
    const box = $('#rar-mgr-cards'); if (!box || !S.stats || !S.stats.manager) return;
    const m = S.stats.manager, by = m.by_status || {};
    const keys = STATUSES.filter(s => by[s] && by[s].count);
    const bar = keys.map(s => `<i class="seg-st ${stClass(s)}" style="flex:${by[s].count}"></i>`).join('');
    box.innerHTML = [
        card({ key:'m-all', hue:'--c-allorders', icon:'list', label:'All Orders', wide:true, num:`${fmtNum(m.total, 0)}<small>orders</small>`,
            extra:`<span class="dist" aria-hidden="true">${bar}</span><span class="dist-lg">${keys.map(s => `<span class="${stClass(s)}"><i class="dot" style="background:var(--sc)"></i><b>${fmtNum(by[s].count, 0)}</b> ${esc(stLabel(s))}</span>`).join('')}</span>`,
            sub:'সব order দেখুন, খুঁজুন ও status update করুন' }),
        card({ key:'m-live', hue:'--c-liveorders', icon:'truck', label:'Live Orders', num:`${fmtNum(m.live, 0)}<small>running</small>`,
            sub: m.live ? `Pending ${m.pending} · On hold ${m.on_hold}${m.oldest_live ? ' · oldest ' + waitFor(m.oldest_live * 1000) : ''}` : 'কোনো running order নেই' }),
        card({ key:'m-proc', hue:'--c-processing', icon:'clock', label:'Total Processing', num:`${fmtNum(m.processing, 0)}<small>orders</small>`,
            sub:`${esc(money(m.processing_value))} to be delivered` }),
    ].join('');
}
function niceMax(m) { m = Math.max(m, 1) * 1.12; const p = Math.pow(10, Math.floor(Math.log10(m))); for (const s of [1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10]) if (s * p >= m) return s * p; return 10 * p; }
function renderSales7() {
    const box = $('#rar-sales7'); if (!box || !S.stats) return;
    const days = S.stats.sales7 || [];
    const W = chartW(box, 340), H = 176, pl = 44, pr = 6, pt = 22, pb = 28, cw = (W - pl - pr) / Math.max(days.length, 1), bw = Math.min(26, cw * .56);
    const maxV = Math.max(0, ...days.map(d => d.sales)), top = niceMax(maxV), y = v => pt + (H - pt - pb) * (1 - v / top);
    let g = '';
    [0, top / 2, top].forEach(v => { g += `<line class="g" x1="${pl}" x2="${W - pr}" y1="${y(v)}" y2="${y(v)}"/><text class="yl" x="${pl - 7}" y="${y(v) + 4}" text-anchor="end">${esc(moneyShort(v))}</text>`; });
    const maxI = days.findIndex(d => d.sales === maxV);
    days.forEach((d, i) => {
        const cx = pl + i * cw + cw / 2, h = Math.max(y(0) - y(d.sales), d.sales ? 2 : 0), isT = i === days.length - 1;
        g += `<rect class="bar${isT ? ' today' : ''}" x="${cx - bw / 2}" y="${y(0) - h}" width="${bw}" height="${h}" rx="4"><title>${ymdLabel(d.date)}: ${esc(money(d.sales))} · ${d.orders} orders</title></rect>`;
        if ((isT || i === maxI) && d.sales) g += `<text class="vl" x="${cx}" y="${Math.max(12, y(d.sales) - 6)}" text-anchor="middle">${esc(moneyShort(d.sales))}</text>`;
        g += `<text class="xl${isT ? ' today' : ''}" x="${cx}" y="${H - 8}" text-anchor="middle">${isT ? 'Today' : ymdWeekday(d.date)}</text>`;
    });
    const wk = sum(days, d => d.sales);
    box.innerHTML = `<div class="p-h"><h3>Sales · last 7 days</h3><span>${esc(money(wk))} total · ${esc(money(wk / 7))}/day</span></div><svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Sales for the last 7 days">${g}</svg>`;
}
function renderAttention() {
    const box = $('#rar-attn'); if (!box || !S.stats) return;
    const s = S.stats.stock, rows = [];
    const names = list => (list || []).map(x => shortName(x.name)).join(', ');
    if (s.low) rows.push({ tone:'low', n:s.low, t:`${s.low} products running low (1–${THRESHOLD})`, sub:names(S.stats.low_names), open:'att-low' });
    if (s.out) rows.push({ tone:'out', n:s.out, t:`${s.out} products out of stock`, sub:names(S.stats.out_names), open:'s-out' });
    if (MANAGER && S.stats.manager) {
        const m = S.stats.manager;
        if (m.stale) rows.push({ tone:'warn', n:m.stale, t:`${m.stale} live orders waiting 24h+`, sub:m.oldest_live ? `Oldest waiting ${waitFor(m.oldest_live * 1000)}` : '', open:'att-stale' });
        if (m.on_hold) rows.push({ tone:'hold', n:m.on_hold, t:`${m.on_hold} orders on hold`, sub:'Customer confirm বা payment-এর অপেক্ষায়', open:'att-hold' });
    } else if (canOrders && S.stats.live_today) {
        rows.push({ tone:'info', n:S.stats.live_today, t:`${S.stats.live_today} of today's orders still running`, sub:'Pending, processing ও on-hold orders', open:'att-today-live' });
    }
    box.innerHTML = `<div class="p-h"><h3>Needs attention</h3><span>${rows.length ? rows.length + ' items' : ''}</span></div>` +
        (rows.length ? `<div class="attn">${rows.map(r => `<button type="button" class="attn-row" data-open="${r.open}"><span class="ai ${r.tone}">${r.n}</span><span class="at"><b>${esc(r.t)}</b><span>${esc(r.sub)}</span></span>${ICON.chev}</button>`).join('')}</div>`
            : '<div class="allgood">সব ঠিক আছে — এখন কিছু করার নেই।</div>');
}
function renderRecent() {
    const box = $('#rar-recent'); if (!box || !S.stats) return;
    const L = S.stats.recent || [];
    box.innerHTML = `<div class="p-h"><h3>Recent orders</h3><button type="button" class="link-btn" data-open="${MANAGER ? 'm-all' : 'k-orders-today'}">See all</button></div>` +
        (L.length ? L.map(o => orderRow(o, { ro:true })).join('') : '<div class="allgood">এখনো কোনো order নেই।</div>');
}
function renderDashboard() { renderKPIs(); renderStockCards(); renderMgrCards(); renderSales7(); renderAttention(); renderRecent(); }

async function loadStats() {
    if (S.loading) return;
    S.loading = true;
    try {
        S.stats = await api('stats', { period:S.period });
        renderDashboard();
    } catch (e) { fail(e); } finally { S.loading = false; }
}

/* ---------------- Sales & Growth (Shop Manager) ---------------- */
const chartW = (el, fallback) => { const w = el ? el.clientWidth - 34 : 0; return w > 200 ? Math.round(w) : fallback; };
const pickLabels = (n, xOf, gap) => { const out = []; for (let i = 0; i < n; i++) { const x = xOf(i); if (i === n - 1) { while (out.length && x - xOf(out[out.length - 1]) < gap) out.pop(); out.push(i); } else if (!out.length || x - xOf(out[out.length - 1]) >= gap) out.push(i); } return new Set(out); };
function lineChart(cur, prev, opt) {
    const W = opt.w || 680, H = W < 500 ? 210 : 240, pl = 52, pr = 12, pt = 18, pb = 30, n = cur.length;
    const maxV = Math.max(0, ...cur.map(d => d.v), ...prev.map(d => d.v)), top = niceMax(maxV);
    const x = i => pl + (n <= 1 ? 0 : i * (W - pl - pr) / (n - 1)), y = v => pt + (H - pt - pb) * (1 - v / top);
    let g = '';
    [0, top / 4, top / 2, top * 3 / 4, top].forEach(v => { g += `<line class="g" x1="${pl}" x2="${W - pr}" y1="${y(v)}" y2="${y(v)}"/><text class="yl" x="${pl - 8}" y="${y(v) + 4}" text-anchor="end">${esc(opt.fmt(v))}</text>`; });
    if (prev.length) g += `<path class="line prev" d="${prev.slice(0, n).map((d, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(d.v).toFixed(1)}`).join('')}"/>`;
    const path = cur.map((d, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(d.v).toFixed(1)}`).join('');
    g += `<path class="area" d="${path}L${x(n - 1).toFixed(1)},${y(0)}L${x(0).toFixed(1)},${y(0)}Z"/><path class="line" d="${path}"/>`;
    const show = pickLabels(n, x, 64), cx = i => Math.min(Math.max(x(i), pl + 18), W - pr - 20);
    cur.forEach((d, i) => {
        if (show.has(i)) g += `<text class="xl${i === n - 1 ? ' today' : ''}" x="${cx(i)}" y="${H - 8}" text-anchor="middle">${esc(d.label)}</text>`;
        g += `<circle class="pt" cx="${x(i)}" cy="${y(d.v)}" r="${n > 40 ? 2 : 3.2}"><title>${esc(d.label)}: ${esc(opt.fmt(d.v, true))}${prev[i] ? ' · previous ' + esc(opt.fmt(prev[i].v, true)) : ''}</title></circle>`;
    });
    const mi = cur.reduce((b, d, i) => d.v > cur[b].v ? i : b, 0);
    if (cur[mi] && cur[mi].v) g += `<text class="vl" x="${Math.min(Math.max(x(mi), pl + 20), W - pr - 20)}" y="${Math.max(12, y(cur[mi].v) - 9)}" text-anchor="middle">${esc(opt.fmt(cur[mi].v))}</text>`;
    return `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(opt.aria)}">${g}</svg>`;
}
function barChart(vals, labels, fmt, aria, w) {
    const W = w || 680, H = W < 500 ? 180 : 200, pl = 40, pr = 8, pt = 16, pb = 28, n = vals.length, cw = (W - pl - pr) / Math.max(n, 1), bw = Math.max(2, Math.min(22, cw * .62));
    const top = niceMax(Math.max(0, ...vals)), y = v => pt + (H - pt - pb) * (1 - v / top);
    let g = '';
    [0, top / 2, top].forEach(v => { g += `<line class="g" x1="${pl}" x2="${W - pr}" y1="${y(v)}" y2="${y(v)}"/><text class="yl" x="${pl - 7}" y="${y(v) + 4}" text-anchor="end">${esc(fmt(v))}</text>`; });
    const bx = i => pl + i * cw + cw / 2, show = pickLabels(n, bx, 64);
    vals.forEach((v, i) => {
        const cx = bx(i), h = Math.max(y(0) - y(v), v ? 2 : 0);
        g += `<rect class="bar${i === n - 1 ? ' today' : ''}" x="${cx - bw / 2}" y="${y(0) - h}" width="${bw}" height="${h}" rx="3"><title>${esc(labels[i])}: ${esc(fmt(v))}</title></rect>`;
        if (show.has(i)) g += `<text class="xl${i === n - 1 ? ' today' : ''}" x="${Math.min(Math.max(cx, pl + 18), W - pr - 20)}" y="${H - 8}" text-anchor="middle">${esc(labels[i])}</text>`;
    });
    return `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(aria)}">${g}</svg>`;
}
const DONUT = ['var(--c-sales)', 'var(--c-orders)', 'var(--c-liveorders)', 'var(--c-processing)', 'var(--c-allorders)', 'var(--c-return)', 'var(--muted)'];
function donut(parts, center) {
    const total = sum(parts, p => p.v) || 1, R = 52, r = 34, cx = 66, cy = 66;
    let a0 = -Math.PI / 2, g = '';
    parts.forEach((p, i) => {
        const frac = p.v / total; if (!frac) return;
        const a1 = a0 + frac * Math.PI * 2 - (frac >= 1 ? 0.0001 : 0), large = a1 - a0 > Math.PI ? 1 : 0;
        const P = (rad, a) => `${(cx + rad * Math.cos(a)).toFixed(2)},${(cy + rad * Math.sin(a)).toFixed(2)}`;
        g += `<path d="M${P(R, a0)}A${R},${R} 0 ${large} 1 ${P(R, a1)}L${P(r, a1)}A${r},${r} 0 ${large} 0 ${P(r, a0)}Z" fill="${p.color || DONUT[i % DONUT.length]}"><title>${esc(p.name)}: ${Math.round(frac * 100)}%</title></path>`;
        a0 = a1;
    });
    g += `<text x="${cx}" y="${cy + 5}" text-anchor="middle" font-size="15" font-weight="800" fill="var(--ink)">${esc(center)}</text>`;
    return `<svg viewBox="0 0 132 132" aria-hidden="true">${g}</svg>`;
}
function renderGrowth() {
    const box = $('#rar-growth'); if (!box) return;
    const R = S.report; if (!R) { box.innerHTML = '<div class="panel skel tall"></div>'; return; }
    const c = R.cur, p = R.prev, days = R.daily || [];
    const cur = days.map(d => ({ v:d.sales, label:ymdLabel(d.date) }));
    const prevDaily = R.prev_daily || [];
    const g = (v, pv, good = true) => { const x = pct(v, pv); return x === null ? '<small>no earlier data</small>' : `<small><span class="d ${x === 0 ? 'flat' : (x > 0) === good ? 'good' : 'bad'}">${x > 0 ? '▲' : x < 0 ? '▼' : '•'} ${Math.abs(x)}%</span> vs previous ${R.days} days</small>`; };
    const statusParts = STATUSES.filter(s => (R.by_status || {})[s]).map(s => ({ name:stLabel(s), v:R.by_status[s], color:`var(--st-${KNOWN_ST.includes(s) ? s : 'other'})` }));
    const payParts = (R.payments || []).map(x => ({ name:x.name, v:x.sales }));
    const full = chartW(box, 680), half = window.innerWidth >= 900 ? Math.round((full + 34 - 14) / 2 - 34) : full;
    const maxTop = Math.max(1, ...(R.top || []).map(t => t.sales));
    const maxCh = Math.max(1, ...(R.channels || []).map(t => t.sales));
    box.innerHTML = `<div class="growth">
        <div class="g-kpis">
            <div class="g-kpi"><span>Sales · ${R.days} days</span><b>${esc(moneyCard(c.sales))}</b>${g(c.sales, p.sales)}</div>
            <div class="g-kpi"><span>Orders</span><b>${fmtNum(c.orders, 0)}</b>${g(c.orders, p.orders)}</div>
            <div class="g-kpi"><span>Average order</span><b>${esc(money(c.avg))}</b>${g(c.avg, p.avg)}</div>
            <div class="g-kpi"><span>Items sold</span><b>${qtyFmt(R.items || 0)}</b><small>${c.cancelled + c.returned} cancelled / returned</small></div>
        </div>
        <div class="panel chart"><div class="p-h"><h3>Sales trend</h3><span>${esc(money(c.sales))} in ${R.days} days</span></div>
            ${lineChart(cur, prevDaily.map(d => ({ v:d.sales })), { w:full, fmt:(v, whole) => whole ? money(v) : moneyShort(v), aria:'Daily sales' })}
            <div class="legend-row"><span><i></i>This period</span>${prevDaily.length ? '<span><i class="prev"></i>Previous period</span>' : ''}</div></div>
        <div class="g-grid">
            <div class="panel chart"><div class="p-h"><h3>Orders per day</h3><span>${fmtNum(c.sale_orders, 0)} counted</span></div>${barChart(days.map(d => d.orders), days.map(d => ymdLabel(d.date)), v => fmtNum(v, 0), 'Orders per day', half)}</div>
            <div class="panel"><div class="p-h"><h3>Top products</h3><span>by sales</span></div>
                ${(R.top || []).length ? `<div class="hbars">${R.top.map(t => `<div class="hbar"><span class="nm">${esc(t.name)}</span><span class="vl">${esc(money(t.sales))}</span><span class="hb-tr"><i style="width:${Math.max(3, t.sales / maxTop * 100)}%"></i></span><small>${qtyFmt(t.qty)} sold</small></div>`).join('')}</div>` : '<div class="allgood">এই সময়ে কোনো বিক্রি নেই।</div>'}</div>
            <div class="panel"><div class="p-h"><h3>Payment methods</h3></div>
                ${payParts.length ? `<div class="donut-wrap">${donut(payParts, fmtNum(c.sale_orders, 0))}<div class="donut-lg">${(R.payments || []).map((x, i) => `<span><i class="dot" style="background:${DONUT[i % DONUT.length]}"></i>${esc(x.name)}<b>${esc(moneyShort(x.sales))}</b></span>`).join('')}</div></div>` : '<div class="allgood">কোনো data নেই।</div>'}</div>
            <div class="panel"><div class="p-h"><h3>Order status mix</h3><span>${fmtNum(c.orders, 0)} orders</span></div>
                ${statusParts.length ? `<div class="donut-wrap">${donut(statusParts, fmtNum(c.orders, 0))}<div class="donut-lg">${statusParts.map(x => `<span><i class="dot" style="background:${x.color}"></i>${esc(x.name)}<b>${fmtNum(x.v, 0)}</b></span>`).join('')}</div></div>` : '<div class="allgood">কোনো data নেই।</div>'}</div>
            <div class="panel"><div class="p-h"><h3>Sales channels</h3><span>where orders come from</span></div>
                ${(R.channels || []).length ? `<div class="hbars">${R.channels.map(t => `<div class="hbar"><span class="nm">${esc(t.name)}</span><span class="vl">${esc(money(t.sales))}</span><span class="hb-tr"><i style="width:${Math.max(3, t.sales / maxCh * 100)}%;background:var(--c-orders)"></i></span><small>${t.orders} orders</small></div>`).join('')}</div>` : '<div class="allgood">কোনো data নেই।</div>'}</div>
        </div></div>`;
}
async function loadReport() {
    if (!MANAGER || !$('#rar-growth')) return;
    try { S.report = await api('report', { days:S.days }); renderGrowth(); } catch (e) { fail(e); }
}

/* ================================================================
   Shared rows
   ================================================================ */
const pill = s => `<span class="pill ${stClass(s)}">${esc(stLabel(s))}</span>`;
function statusCtl(o, ro) {
    if (ro || !MANAGER) return pill(o.status);
    const opts = STATUSES.includes(o.status) ? STATUSES : [o.status].concat(STATUSES);
    return `<label class="ssel ${stClass(o.status)}"><span class="sr">Status of order ${esc(o.number)}</span><select id="st-${o.id}" data-status-for="${o.id}">${opts.map(s => `<option value="${esc(s)}"${s === o.status ? ' selected' : ''}>${esc(stLabel(s))}</option>`).join('')}</select></label>`;
}
function orderRow(o, opt = {}) {
    const area = [o.city, o.district].filter(Boolean).join(', ');
    const wait = opt.wait && LIVE.has(o.status) ? ` · <span class="wait">waiting ${waitFor(o.time * 1000)}</span>` : '';
    return `<div class="orow" data-oid="${o.id}">
        <button type="button" class="o-main" data-open-order="${o.id}">
            <span class="o-l1"><span class="mono o-id">#${esc(o.number)}</span><span class="o-name">${esc(o.name)}</span>${o.channel === 'Staff app' ? '<span class="tag">Staff</span>' : ''}</span>
            <span class="o-l2">${when(o.time * 1000)} · ${o.items} item${o.items === 1 ? '' : 's'}${area ? ' · ' + esc(area) : ''}${wait}</span>
        </button>
        <div class="o-amt"><b>${esc(money(o.total))}</b><small>${esc(o.payment || '')}</small></div>
        <div class="o-st">${statusCtl(o, opt.ro)}</div>
    </div>`;
}

/* ================================================================
   Sheet (full-screen panel on phones, dialog on desktop)
   ================================================================ */
const E = { wrap:$('#rar-sheet-wrap'), sheet:$('#rar-sheet'), title:$('#rar-sheet-title'), sub:$('#rar-sheet-sub'), ico:$('#rar-sh-ico'), back:$('#rar-sh-back'), close:$('#rar-sh-close'), tools:$('#rar-sh-tools'), body:$('#rar-sh-body'), foot:$('#rar-sh-foot') };
if (E.back) E.back.innerHTML = ICON.back;
if (E.close) E.close.innerHTML = ICON.x;
const stack = []; let lastFocus = null;
const top = () => stack[stack.length - 1];
const isTop = v => top() === v;
function openView(v, push) {
    if (!E.wrap) return;
    if (!push) { stack.length = 0; lastFocus = document.activeElement; }
    stack.push(v);
    if (E.wrap.hidden) { E.wrap.hidden = false; document.documentElement.classList.add('sheet-open'); setTimeout(() => E.close.focus(), 30); }
    drawView(true);
    if (v.fetch) v.fetch(true);
}
function drawView(fresh) {
    const v = top();
    E.sheet.style.setProperty('--c', `var(${v.hue})`);
    E.ico.innerHTML = ICON[v.icon] || '';
    E.title.textContent = v.title;
    E.back.hidden = stack.length < 2;
    E.tools.hidden = !v.tools; E.tools.innerHTML = '';
    if (v.tools) v.tools(E.tools, v);
    if (fresh) E.body.scrollTop = 0;
    drawBody(fresh);
}
function drawBody(fresh) {
    const v = top(); if (!v) return;
    const st = E.body.scrollTop;
    v.body(E.body, v);
    E.sub.textContent = v.sub ? v.sub(v) : '';
    if (!fresh) E.body.scrollTop = st;
    drawFoot();
}
function drawFoot() {
    const v = top(); if (!v) return;
    let has = false;
    if (v.confirmClose) {
        E.foot.innerHTML = `<div class="confirm"><span>${esc(v.dirtyMsg())}</span><div><button type="button" class="btn btn-sm" data-act="keep">Keep editing</button><button type="button" class="btn btn-sm btn-danger" data-act="discard-close">${v.backIntent ? 'Discard &amp; go back' : 'Discard &amp; close'}</button></div></div>`;
        has = true;
    } else if (v.foot) has = v.foot(E.foot, v);
    E.foot.hidden = !has;
    document.documentElement.style.setProperty('--foot-h', has ? E.foot.offsetHeight + 'px' : '0px');
}
function closeSheet() {
    E.wrap.hidden = true; document.documentElement.classList.remove('sheet-open');
    stack.length = 0;
    loadStats();
    if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
}
function goBack() { stack.pop(); if (stack.length) { drawView(false); const v = top(); if (v.onReturn) v.onReturn(); } else closeSheet(); }
function requestClose() {
    const v = top();
    if (v && v.dirty && v.dirty() && !v.confirmClose) { v.confirmClose = true; v.backIntent = false; drawFoot(); const b = E.foot.querySelector('[data-act="keep"]'); if (b) b.focus(); return; }
    closeSheet();
}
if (E.close) {
    E.close.addEventListener('click', requestClose);
    $('#rar-backdrop').addEventListener('click', requestClose);
    E.back.addEventListener('click', () => {
        const v = top();
        if (v && v.dirty && v.dirty() && !v.confirmClose) { v.confirmClose = true; v.backIntent = true; drawFoot(); return; }
        goBack();
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !E.wrap.hidden) requestClose(); });
    document.addEventListener('pointerdown', e => { const v = top(); if (v && v.onOutside && !E.wrap.hidden) v.onOutside(e); }, true);
    const dispatch = (zone, type) => e => {
        const v = top(); if (!v) return;
        if (type === 'click') {
            const oo = e.target.closest('[data-open-order]');
            if (oo && zone === 'body') { openView(orderView(+oo.dataset.openOrder), true); return; }
            const a = e.target.closest('[data-act]');
            if (a && zone === 'foot' && a.dataset.act === 'keep') { v.confirmClose = false; v.backIntent = false; drawFoot(); return; }
            if (a && zone === 'foot' && a.dataset.act === 'discard-close') { v.backIntent ? goBack() : closeSheet(); return; }
        }
        if (type === 'change' && zone === 'body') { const s = e.target.closest('[data-status-for]'); if (s) { changeStatus(s); return; } }
        if (type === 'submit') e.preventDefault();
        const h = v[`on_${zone}_${type}`]; if (h) h(e, v);
    };
    ['click', 'input', 'change', 'keydown', 'focusin', 'focusout', 'submit'].forEach(t => {
        E.body.addEventListener(t, dispatch('body', t));
        E.tools.addEventListener(t, dispatch('tools', t));
        E.foot.addEventListener(t, dispatch('foot', t));
    });
}
const loadingHtml = (t = 'Loading…') => `<div class="loading"><span class="spin"></span>${esc(t)}</div>`;
const errorHtml = (msg, act = 'retry') => `<div class="empty"><b>Could not load</b>${esc(msg)}<br><br><button type="button" class="btn" data-act="${act}">Try again</button></div>`;

/* ---------------- status changes (Shop Manager) ---------------- */
async function changeStatus(sel) {
    const id = +sel.dataset.statusFor, to = sel.value, wrap = sel.closest('.ssel');
    const v = top(), item = v && v.findOrder ? v.findOrder(id) : null;
    const from = item ? item.status : (v && v.order ? v.order.status : '');
    if (!from || from === to) return;
    wrap && wrap.classList.add('busy'); sel.disabled = true;
    try {
        const res = await api('order_status', { id, status:to });
        if (v && v.onStatus) v.onStatus(res.order, from);
        stack.forEach(x => { if (x !== v && x.onStatus) x.onStatus(res.order, from, true); });
        toast(`<b>#${esc(res.order.number)}</b> → ${esc(stLabel(res.order.status))}${v && v.dropsOut && v.dropsOut(res.order) ? ' · এই list থেকে সরানো হলো' : ''}`, {
            action:'Undo', fn:async () => {
                try { const back = await api('order_status', { id, status:from }); stack.forEach(x => { if (x.onStatus) x.onStatus(back.order, to, x !== top()); }); toast(`<b>#${esc(back.order.number)}</b> আবার ${esc(stLabel(from))}`); } catch (e) { fail(e); }
            },
        });
    } catch (e) {
        fail(e); sel.value = from;
    } finally { wrap && wrap.classList.remove('busy'); sel.disabled = false; }
}

/* ================================================================
   Order lists
   ================================================================ */
function ordersView(cfg) {
    const v = Object.assign({ q:'', chip:'all', page:1, items:[], total:0, pages:1, counts:{}, loading:false, error:'', scope:'today', group:'all', chips:null, req:0 }, cfg);
    v.base = () => v.chips ? v.chips.filter(k => k !== 'all') : [];
    v.findOrder = id => v.items.find(o => o.id === id);
    v.dropsOut = o => (v.group === 'live' || v.scope === 'stale') && !LIVE.has(o.status) || (v.fixed && o.status !== v.fixed) || (v.chip !== 'all' && o.status !== v.chip) || (v.group === 'void' && !isVoid(o.status)) || (v.group === 'sale' && isVoid(o.status));
    v.fetch = async reset => {
        const my = ++v.req;
        if (reset) { v.page = 1; v.loading = true; v.error = ''; if (isTop(v)) drawBody(true); }
        try {
            const res = await api('orders', { scope:v.scope, status:v.fixed || (v.chip !== 'all' ? v.chip : v.group), search:v.q.trim(), page:v.page, per_page:30, sort:v.oldestFirst ? 'old' : 'new' });
            if (my !== v.req) return;
            v.items = reset ? res.items : v.items.concat(res.items);
            v.total = res.total; v.pages = res.pages; v.counts = res.counts || {}; v.error = '';
        } catch (e) { if (my !== v.req) return; v.error = e.message; }
        v.loading = false;
        if (isTop(v)) drawBody(false);
    };
    v.onStatus = (o, from, silent) => {
        const i = v.items.findIndex(x => x.id === o.id);
        if (v.counts[from] !== undefined) v.counts[from] = Math.max(0, v.counts[from] - 1);
        if (v.counts[o.status] !== undefined) v.counts[o.status]++;
        if (i >= 0) { if (v.dropsOut(o)) { v.items.splice(i, 1); v.total = Math.max(0, v.total - 1); } else v.items[i] = Object.assign(v.items[i], o); }
        if (isTop(v)) drawBody(false);
    };
    v.sub = () => v.loading && !v.items.length ? 'Loading…' : `${fmtNum(v.total, 0)} order${v.total === 1 ? '' : 's'}${v.totalLine ? ' · ' + v.totalLine() : ''}${!MANAGER ? ' · view only' : ''}`;
    v.tools = t => {
        t.innerHTML = `<div class="t-row"><div class="search">${ICON.search}<input type="search" id="oq-${v.key}" placeholder="Search order #, name or phone" value="${esc(v.q)}" autocomplete="off"></div>${v.dateSel ? `<select id="oscope" aria-label="Date range"><option value="all">All time</option><option value="today">Today</option><option value="7d">Last 7 days</option><option value="month">This month</option></select>` : ''}</div>${v.chips ? '<div class="chips" id="ochips"></div>' : ''}`;
        const ds = t.querySelector('#oscope'); if (ds) ds.value = v.scope;
    };
    v.drawChips = () => {
        const c = E.tools.querySelector('#ochips'); if (!c) return;
        const keys = v.base(), all = sum(keys, k => v.counts[k] || 0);
        c.innerHTML = v.chips.filter(k => k === 'all' || v.counts[k] !== undefined).map(k => `<button type="button" class="chip${k !== 'all' ? ' ' + stClass(k) : ''}" data-chip="${esc(k)}" aria-pressed="${v.chip === k}">${k !== 'all' ? '<i class="dot st"></i>' : ''}${esc(k === 'all' ? 'All' : stLabel(k))}<b>${fmtNum(k === 'all' ? all : v.counts[k] || 0, 0)}</b></button>`).join('');
    };
    v.body = b => {
        v.drawChips();
        if (v.loading && !v.items.length) { b.innerHTML = loadingHtml(); return; }
        if (v.error && !v.items.length) { b.innerHTML = errorHtml(v.error); return; }
        b.innerHTML = v.items.length
            ? v.items.map(o => orderRow(o, { wait:v.oldestFirst })).join('') + (v.page < v.pages ? `<button type="button" class="btn more" data-act="more"${v.loading ? ' disabled' : ''}>${v.loading ? '<span class="spin"></span>Loading…' : `Show more · ${fmtNum(v.total - v.items.length, 0)} left`}</button>` : '')
            : `<div class="empty"><b>No orders here</b>${v.q ? 'অন্য নাম, নম্বর বা order # দিয়ে খুঁজুন' : 'এই তালিকায় এখন কোনো order নেই'}</div>`;
    };
    if (v.totalLine) v.foot = f => { f.innerHTML = `<div class="tot-bar"><span>Sales for this period (cancelled / refunded / failed বাদে)</span><b>${esc(v.totalLine())}</b></div>`; return true; };
    let timer;
    v.on_body_click = e => { const a = e.target.closest('[data-act]'); if (!a) return; if (a.dataset.act === 'more' && !v.loading) { v.page++; v.loading = true; drawBody(false); v.fetch(false); } if (a.dataset.act === 'retry') v.fetch(true); };
    v.on_tools_input = e => { if (e.target.type === 'search') { v.q = e.target.value; clearTimeout(timer); timer = setTimeout(() => v.fetch(true), 320); } };
    v.on_tools_change = e => { if (e.target.id === 'oscope') { v.scope = e.target.value; v.fetch(true); } };
    v.on_tools_click = e => { const c = e.target.closest('[data-chip]'); if (c && c.dataset.chip !== v.chip) { v.chip = c.dataset.chip; v.fetch(true); } };
    return v;
}

/* ================================================================
   Order detail + slip
   ================================================================ */
function orderView(id) {
    const v = { title:'Order', hue:'--c-orders', icon:'orders', order:null, error:'' };
    v.fetch = async () => {
        try { const res = await api('order_detail', { id }); v.order = res.order; v.title = 'Order #' + res.order.number; v.error = ''; }
        catch (e) { v.error = e.message; }
        if (isTop(v)) drawView(false);
    };
    v.onStatus = o => { if (v.order && v.order.id === o.id) { v.order.status = o.status; v.order.status_label = o.status_label; v.fetch(); } };
    v.sub = () => v.order ? `${when(v.order.time * 1000)} · ${v.order.channel}` : '';
    v.body = b => {
        if (v.error) { b.innerHTML = errorHtml(v.error); return; }
        const o = v.order; if (!o) { b.innerHTML = loadingHtml(); return; }
        const lines = o.lines || [];
        b.innerHTML = `
        <div class="dcard d-status">
            <div><span class="k">Status</span>${statusCtl(o)}</div>
            <div><span class="k">Total</span><span class="big">${esc(money(o.total))}</span></div>
            <div><span class="k">Payment</span><b>${esc(o.payment || '—')}</b></div>
            <div><span class="k">Created by</span><b>${esc(o.created_by || o.channel)}</b></div>
            <div class="d-acts"><button type="button" class="btn btn-primary btn-sm" data-act="slip">${ICON.share}Sales slip · Share</button>${o.admin_url ? `<a class="btn btn-sm" href="${esc(o.admin_url)}" target="_blank" rel="noopener">Open in WooCommerce</a>` : ''}</div>
            ${MANAGER ? '' : '<p class="note-sm">Status পরিবর্তন শুধু Shop Manager login থেকে করা যাবে।</p>'}
        </div>
        <div class="dgrid">
            <div class="dcard"><h4>Customer &amp; shipping</h4><dl class="kv">
                <div><dt>Name</dt><dd>${esc(o.name)}</dd></div>
                <div><dt>Contact</dt><dd class="mono sel-all">${o.phone ? '+88 ' + esc(o.phone.replace(/^\+?88/, '')) : '—'}</dd></div>
                <div><dt>Email</dt><dd>${o.email ? `<span class="sel-all">${esc(o.email)}</span>` : '—'}</dd></div>
                <div><dt>Address</dt><dd>${esc(o.address || '—')}</dd></div>
                <div><dt>Town / City · District</dt><dd>${esc([o.city, o.district].filter(Boolean).join(', ') || '—')}</dd></div>
            </dl></div>
            <div class="dcard"><h4>Items</h4><div class="tbl-wrap"><table class="itbl">
                <thead><tr><th>Sl</th><th>Product</th><th class="r">Qty × Rate</th><th class="r">Amount</th></tr></thead>
                <tbody>${lines.map((l, i) => `<tr><td>${i + 1}</td><td>${esc(l.name)}${l.sku ? `<br><span class="mono" style="font-size:12px;color:var(--muted)">${esc(l.sku)}</span>` : ''}</td><td class="r">${qtyFmt(l.qty)} × ${esc(money(l.price))}</td><td class="r">${esc(money(l.total))}</td></tr>`).join('')}</tbody>
                <tfoot><tr><td colspan="3">Items subtotal</td><td class="r">${esc(money(o.subtotal))}</td></tr>
                ${o.discount ? `<tr><td colspan="3">Discount</td><td class="r">−${esc(money(o.discount))}</td></tr>` : ''}
                ${o.fees ? `<tr><td colspan="3">Fees</td><td class="r">${esc(money(o.fees))}</td></tr>` : ''}
                <tr><td colspan="3">Shipping</td><td class="r">${esc(money(o.shipping))}</td></tr>
                ${o.tax ? `<tr><td colspan="3">Tax</td><td class="r">${esc(money(o.tax))}</td></tr>` : ''}
                <tr class="tot"><td colspan="3">Total</td><td class="r">${esc(money(o.total))}</td></tr>
                <tr class="words"><td colspan="4">${esc(inWords(o.total))}</td></tr></tfoot>
            </table></div></div>
        </div>
        ${o.note ? `<div class="dcard" style="margin-top:12px"><h4>Customer note</h4><p style="margin:0">${esc(o.note)}</p></div>` : ''}
        ${(o.notes || []).length ? `<div class="dcard" style="margin-top:12px"><h4>Timeline</h4><ol class="tl">${o.notes.map(n => `<li><i class="tl-dot"></i><div><b>${esc(n.text)}</b><span>${when(n.time * 1000)} · ${esc(n.by)}${n.customer ? ' · note to customer' : ''}</span></div></li>`).join('')}</ol></div>` : ''}`;
    };
    v.on_body_click = e => { const a = e.target.closest('[data-act]'); if (!a) return; if (a.dataset.act === 'slip' && v.order) openView(slipView(v.order), true); if (a.dataset.act === 'retry') v.fetch(); };
    return v;
}

const SLIP_FONT = (w, s) => `${w} ${s}px system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans","Noto Sans Bengali",Arial,sans-serif`;
const ST_INK = { pending:'#8a6300', processing:'#2445b8', 'on-hold':'#6e2ba3', completed:'#17733a', cancelled:'#5b6477', refunded:'#b4173f', failed:'#b42318' };
function slipPaint(x, o, D) {
    const W = 1080, PAD = 64, R = W - PAD, INK = '#131b31', MUT = '#66718c', INK2 = '#3a4562', NAVY = '#15234a';
    const t = (s, X, Y, w, sz, col, al) => { if (!D) return; x.font = SLIP_FONT(w, sz); x.fillStyle = col; x.textAlign = al || 'left'; x.textBaseline = 'alphabetic'; x.fillText(String(s), X, Y); };
    const box = (X, Y, w, h, col, r) => { if (!D) return; x.fillStyle = col; x.beginPath(); if (r && x.roundRect) x.roundRect(X, Y, w, h, r); else x.rect(X, Y, w, h); x.fill(); };
    const hr = Y => { if (D) { x.fillStyle = '#e0e5ee'; x.fillRect(PAD, Y, W - 2 * PAD, 2); } };
    const wrap = (s, w, sz, maxW) => {
        x.font = SLIP_FONT(w, sz); const out = [];
        String(s || '').split('\n').forEach(par => { let cur = ''; par.split(/\s+/).forEach(word => { const test = cur ? cur + ' ' + word : word; if (cur && x.measureText(test).width > maxW) { out.push(cur); cur = word; } else cur = test; }); out.push(cur); });
        return out;
    };
    const fit = (s, w, sz, maxW) => { x.font = SLIP_FONT(w, sz); let str = String(s); while (str.length > 3 && x.measureText(str).width > maxW) str = str.slice(0, -2); return str === String(s) ? str : str + '…'; };
    let y;
    box(0, 0, W, 196, NAVY);
    t(fit(String(C.business || 'Sales Order').toUpperCase(), 800, 50, 600), PAD, 98, 800, 50, '#ffffff');
    t(fit(C.site || '', 500, 24, 600), PAD, 146, 500, 24, 'rgba(255,255,255,.72)');
    t('SALES ORDER', R, 94, 800, 32, '#ffffff', 'right');
    t('#' + o.number, R, 146, 800, 42, '#a9bdff', 'right');
    y = 196;
    box(0, y, W, 72, '#eef2fb');
    t(`Date: ${dmy(o.time * 1000)} · ${hm(o.time * 1000)}`, PAD, y + 46, 600, 24, INK2);
    t(`● ${stLabel(o.status)}`, R, y + 46, 700, 24, ST_INK[o.status] || '#0b6a8f', 'right');
    y += 72 + 56;
    const cw = (W - 2 * PAD - 48) / 2, X2 = PAD + cw + 48;
    t('CUSTOMER', PAD, y, 800, 19, MUT); t('SHIP TO', X2, y, 800, 19, MUT);
    let yl = y + 42, yr = y + 42;
    wrap(o.name, 800, 28, cw).forEach(l => { t(l, PAD, yl, 800, 28, INK); yl += 36; });
    if (o.phone) { t('+88 ' + String(o.phone).replace(/^\+?88/, ''), PAD, yl, 600, 24, INK); yl += 34; }
    if (o.email) wrap(o.email, 500, 22, cw).forEach(l => { t(l, PAD, yl, 500, 22, MUT); yl += 30; });
    wrap(o.address || '', 600, 24, cw).forEach(l => { t(l, X2, yr, 600, 24, INK); yr += 33; });
    wrap([o.city, o.district].filter(Boolean).join(', '), 700, 24, cw).forEach(l => { t(l, X2, yr, 700, 24, INK); yr += 33; });
    y = Math.max(yl, yr) + 14;
    const cSl = PAD + 22, cItem = PAD + 84, cQty = PAD + 640, cRate = PAD + 800, cAmt = R - 22, itemW = cQty - cItem - 90;
    box(PAD, y, W - 2 * PAD, 58, '#f2f4f9', 12);
    t('SL', cSl, y + 37, 800, 19, MUT); t('ITEM', cItem, y + 37, 800, 19, MUT);
    t('QTY', cQty, y + 37, 800, 19, MUT, 'right'); t('RATE', cRate, y + 37, 800, 19, MUT, 'right'); t('AMOUNT', cAmt, y + 37, 800, 19, MUT, 'right');
    y += 58;
    (o.lines || []).forEach((it, i) => {
        const lines = wrap(it.name, 600, 24, itemW);
        t(String(i + 1), cSl, y + 42, 700, 24, MUT);
        lines.forEach((l, k) => t(l, cItem, y + 42 + k * 32, 600, 24, INK));
        t(qtyFmt(it.qty), cQty, y + 42, 700, 24, INK, 'right');
        t(money(it.price), cRate, y + 42, 500, 24, MUT, 'right');
        t(money(it.total), cAmt, y + 42, 700, 24, INK, 'right');
        y += lines.length * 32 + 30; hr(y - 2);
    });
    y += 40;
    const lx = R - 440;
    const row = (label, val) => { t(label, lx, y, 500, 24, INK2); t(val, cAmt, y, 600, 24, INK, 'right'); y += 40; };
    row('Items subtotal', money(o.subtotal));
    if (o.discount) row('Discount', '−' + money(o.discount));
    if (o.fees) row('Fees', money(o.fees));
    row('Shipping', money(o.shipping));
    if (o.tax) row('Tax', money(o.tax));
    box(lx - 22, y - 10, cAmt - lx + 44, 66, '#e6f4ef', 12);
    t('Total', lx, y + 34, 800, 28, INK); t(money(o.total), cAmt, y + 36, 800, 32, '#0a7f6a', 'right');
    y += 66 + 40;
    t('IN WORDS', PAD, y, 800, 19, MUT); y += 36;
    wrap(inWords(o.total), 700, 25, W - 2 * PAD).forEach(l => { t(l, PAD, y, 700, 25, INK); y += 34; });
    y += 12; hr(y); y += 46;
    t(`Payment: ${o.payment || '—'}`, PAD, y, 600, 22, INK2);
    t(`Source: ${o.channel || ''}`, R, y, 500, 22, MUT, 'right'); y += 34;
    if (o.note) wrap('Note: ' + o.note, 500, 22, W - 2 * PAD).forEach(l => { t(l, PAD, y, 500, 22, INK2); y += 30; });
    y += 26;
    box(0, y, W, 100, '#f2f4f9');
    t(fit(C.slipFooter || 'Thank you!', 700, 25, W - 2 * PAD), W / 2, y + 46, 700, 25, NAVY, 'center');
    t(`Generated ${dmy(Date.now())}, ${hm(Date.now())}${C.site ? '  ·  ' + C.site : ''}`, W / 2, y + 78, 500, 19, MUT, 'center');
    return y + 100;
}
const slipCache = new Map();
async function makeSlip(o) {
    const key = o.id + '|' + o.status;
    if (slipCache.has(key)) return slipCache.get(key);
    const c = document.createElement('canvas'), x = c.getContext('2d');
    c.width = 1080; c.height = 10;
    const h = slipPaint(x, o, false);
    c.width = 1080; c.height = Math.ceil(h);
    x.fillStyle = '#ffffff'; x.fillRect(0, 0, c.width, c.height);
    slipPaint(x, o, true);
    const blob = await new Promise(res => { try { c.toBlob(res, 'image/png'); } catch (e) { res(null); } });
    const rec = { blob, url:blob ? URL.createObjectURL(blob) : c.toDataURL('image/png') };
    slipCache.set(key, rec);
    return rec;
}
function orderText(o) {
    const L = [`*${C.business || 'Sales Order'} — Sales Order #${o.number}*`, `Date: ${dmy(o.time * 1000)}, ${hm(o.time * 1000)}`, `Customer: ${o.name}${o.phone ? ' (+88 ' + String(o.phone).replace(/^\+?88/, '') + ')' : ''}`, `Ship to: ${[o.address, o.city, o.district].filter(Boolean).join(', ')}`, ''];
    (o.lines || []).forEach((it, i) => L.push(`${i + 1}. ${it.name} × ${qtyFmt(it.qty)} = ${money(it.total)}`));
    L.push('', `Items subtotal: ${money(o.subtotal)}`);
    if (o.discount) L.push(`Discount: −${money(o.discount)}`);
    if (o.fees) L.push(`Fees: ${money(o.fees)}`);
    L.push(`Shipping: ${money(o.shipping)}`);
    if (o.tax) L.push(`Tax: ${money(o.tax)}`);
    L.push(`*Total: ${money(o.total)}*`, `(${inWords(o.total)})`, '', `Payment: ${o.payment || '—'} · Status: ${stLabel(o.status)}`);
    if (C.slipFooter) L.push(C.slipFooter);
    return L.join('\n');
}
const waLink = o => `https://wa.me/88${String(o.phone || '').replace(/\D/g, '').replace(/^88/, '')}?text=${encodeURIComponent(orderText(o))}`;
function slipBlock(o) {
    return `<div class="slip-acts"><button type="button" class="btn btn-primary" data-act="share">${ICON.share}Share slip</button><a class="btn btn-wa" href="${esc(waLink(o))}" target="_blank" rel="noopener">WhatsApp</a><button type="button" class="btn" data-act="copy">Copy text</button></div>
        <div class="slip-acts2"><a class="btn" id="rar-slip-dl" href="#" download="sales-order-${esc(o.number)}.png">Download image</a><button type="button" class="btn" data-act="copyimg">Copy image</button></div>
        <p class="slip-tip">Share চাপলে WhatsApp, Messenger সহ ফোনের সব sharing app আসবে। WhatsApp button সরাসরি customer-এর নম্বরে order-এর লেখা পাঠায়।</p>
        <textarea class="copy-fallback" id="rar-copy-fb" readonly hidden aria-label="Order text to copy"></textarea>
        <div class="slip-frame" id="rar-slip-frame"><span class="slip-load"><span class="spin"></span>Slip তৈরি হচ্ছে…</span></div>`;
}
async function fillSlip(o) {
    const rec = await makeSlip(o), f = E.body.querySelector('#rar-slip-frame'), dl = E.body.querySelector('#rar-slip-dl');
    if (f) f.innerHTML = `<img src="${rec.url}" alt="Sales order slip for order #${esc(o.number)}">`;
    if (dl) dl.href = rec.url;
}
async function shareSlip(o) {
    const rec = await makeSlip(o);
    try {
        if (!rec.blob || !navigator.share) throw new Error('no-share');
        const data = { files:[new File([rec.blob], `sales-order-${o.number}.png`, { type:'image/png' })], title:`Sales Order #${o.number}`, text:orderText(o) };
        if (navigator.canShare && !navigator.canShare(data)) throw new Error('no-file-share');
        await navigator.share(data);
    } catch (e) {
        if (e && e.name === 'AbortError') return;
        toast('এই browser-এ সরাসরি share হচ্ছে না — Download image চাপুন, তারপর WhatsApp / Messenger-এ পাঠান।', { long:true });
    }
}
function copyText(o) {
    const text = orderText(o);
    const fallback = () => { const ta = E.body.querySelector('#rar-copy-fb'); if (ta) { ta.hidden = false; ta.value = text; ta.focus(); ta.select(); } toast('Text select করা আছে — copy করে নিন'); };
    try { navigator.clipboard.writeText(text).then(() => toast('Order text copy হয়েছে — WhatsApp / Messenger-এ paste করুন'), fallback); } catch (e) { fallback(); }
}
async function copyImage(o) {
    try {
        const rec = await makeSlip(o);
        if (!rec.blob || !window.ClipboardItem) throw new Error();
        await navigator.clipboard.write([new ClipboardItem({ 'image/png':rec.blob })]);
        toast('Slip image copy হয়েছে — chat-এ paste করুন');
    } catch (e) { toast('এই browser-এ image copy হচ্ছে না — Download image বা Share ব্যবহার করুন'); }
}
function slipActions(e, o) {
    const a = e.target.closest('[data-act]'); if (!a || !o) return false;
    if (a.dataset.act === 'share') { shareSlip(o); return true; }
    if (a.dataset.act === 'copy') { copyText(o); return true; }
    if (a.dataset.act === 'copyimg') { copyImage(o); return true; }
    return false;
}
function slipView(o) {
    const v = { title:`Sales Order #${o.number}`, hue:'--c-sales', icon:'share' };
    v.sub = () => 'Slip image · share, download বা copy করুন';
    v.body = b => { b.innerHTML = `<div class="saved">${slipBlock(o)}</div>`; fillSlip(o); };
    v.on_body_click = e => slipActions(e, o);
    return v;
}

/* ================================================================
   Stock views
   ================================================================ */
const REASONS = ['Restock — new shipment', 'Count correction', 'Damaged / expired', 'Customer return', 'Transfer in', 'Transfer out'];
function stockView(mode, preset) {
    const T = { all:['All Stock', '--c-allstock', 'boxes'], live:['Available / Live Stock', '--c-live', 'pulse'], out:['Out of Stock', '--c-out', 'ban'], manager:['Stock Manager', '--c-allstock', 'sliders'] }[mode];
    const v = { mode, title:T[0], hue:T[1], icon:T[2], staged:new Map(), q:'', chip:preset || 'all', cat:0, sort:'qty_asc', tab:'list', reason:REASONS[0],
        editable:mode !== 'all' && !!C.canStock, items:[], counts:null, total:0, page:1, pages:1, loading:false, error:'', req:0, cats:null, moves:0, log:null };
    v.chipKeys = mode === 'out' ? null : mode === 'live' ? ['all', 'ok', 'low', 'untracked'] : ['all', 'ok', 'low', 'out', 'untracked'];
    v.levelParam = () => mode === 'out' ? 'out' : mode === 'live' ? (v.chip === 'all' ? 'live' : v.chip) : v.chip;
    v.find = id => v.items.find(p => p.id === id);
    v.shown = p => v.staged.has(p.id) ? v.staged.get(p.id) : p.stock_qty;
    v.dirty = () => v.staged.size > 0;
    v.dirtyMsg = () => `${toBn(v.staged.size)}টি পরিবর্তন এখনো save হয়নি`;
    v.fetch = async reset => {
        if (mode === 'manager' && v.tab === 'log') return v.fetchLog(reset);
        const my = ++v.req;
        if (reset) { v.page = 1; v.loading = true; v.error = ''; if (isTop(v)) drawBody(true); }
        try {
            const res = await api('stock_list', { level:v.levelParam(), search:v.q.trim(), category:v.cat || 0, sort:v.sort, page:v.page, per_page:40, with_cats:mode === 'manager' && !v.cats ? 1 : 0 });
            if (my !== v.req) return;
            v.items = reset ? res.items : v.items.concat(res.items.filter(p => !v.find(p.id)));
            v.total = res.total; v.pages = res.pages; v.counts = res.counts; v.error = '';
            if (res.categories) { v.cats = res.categories; v.moves = res.moves_today || 0; if (isTop(v)) v.tools(E.tools, v); }
        } catch (e) { if (my !== v.req) return; v.error = e.message; }
        v.loading = false;
        if (isTop(v)) drawBody(false);
    };
    v.fetchLog = async reset => {
        const my = ++v.req;
        if (reset) { v.log = { items:[], total:0, page:1, loading:true, error:'' }; if (isTop(v)) drawBody(true); }
        try {
            const res = await api('stock_log', { search:v.q.trim(), page:v.log.page });
            if (my !== v.req) return;
            v.log.items = reset ? res.items : v.log.items.concat(res.items); v.log.total = res.total; v.log.error = '';
        } catch (e) { if (my !== v.req) return; v.log.error = e.message; }
        v.log.loading = false;
        if (isTop(v)) drawBody(false);
    };
    v.sub = () => {
        const c = v.counts;
        if (!c) return 'Loading…';
        if (mode === 'all') return `${fmtNum(c.all, 0)} products · ${qtyFmt(c.units)} units · শুধু দেখার জন্য`;
        if (mode === 'live') return `${fmtNum(c.live, 0)} products · qty বদলে Save চাপুন`;
        if (mode === 'out') return `${fmtNum(c.out, 0)} products · stock যোগ করে Save চাপুন`;
        return `${fmtNum(c.all, 0)} products · ${qtyFmt(c.units)} units · ${moneyShort(c.value)}`;
    };
    v.tools = t => {
        let h = '';
        if (mode === 'manager') h += `<div class="tabs" role="tablist" aria-label="Stock Manager sections"><button type="button" role="tab" data-tab="list" aria-selected="${v.tab === 'list'}">Stock list</button><button type="button" role="tab" data-tab="log" aria-selected="${v.tab === 'log'}">Movement log</button></div>`;
        h += `<div class="t-row"><div class="search">${ICON.search}<input type="search" id="sq-${mode}" placeholder="${v.tab === 'log' ? 'Search product or reason' : 'Search product name or SKU'}" value="${esc(v.q)}" autocomplete="off"></div>`;
        if (mode === 'manager' && v.tab === 'list') {
            h += `<select id="scat" aria-label="Category"><option value="0">All categories</option>${(v.cats || []).map(c => `<option value="${c.id}">${esc(c.name)} (${c.count})</option>`).join('')}</select>
            <select id="ssort" aria-label="Sort"><option value="qty_asc">Stock: low → high</option><option value="qty_desc">Stock: high → low</option><option value="name">Name A–Z</option><option value="value">Stock value</option><option value="updated">Recently updated</option></select>`;
        }
        h += '</div>';
        if (v.chipKeys && v.tab === 'list') h += '<div class="chips" id="schips"></div>';
        t.innerHTML = h;
        const c = t.querySelector('#scat'); if (c) c.value = String(v.cat || 0);
        const s = t.querySelector('#ssort'); if (s) s.value = v.sort;
        v.drawChips();
    };
    v.drawChips = () => {
        const c = E.tools.querySelector('#schips'); if (!c || !v.counts) return;
        const n = k => k === 'all' ? (mode === 'live' ? v.counts.live : v.counts.all) : v.counts[k] || 0;
        c.innerHTML = v.chipKeys.filter(k => k !== 'untracked' || v.counts.untracked).map(k => `<button type="button" class="chip" data-lv="${k}" aria-pressed="${v.chip === k}">${k !== 'all' ? `<i class="dot lv-${k}"></i>` : ''}${esc(mode === 'live' && k === 'all' ? LVCHIP.live : LVCHIP[k])}<b>${fmtNum(n(k), 0)}</b></button>`).join('');
    };
    const statsStrip = () => {
        const c = v.counts; if (!c) return '';
        return `<div class="sstrip"><div><span>Products</span><b>${fmtNum(c.all, 0)}</b></div><div><span>Units in hand</span><b>${qtyFmt(c.units)}</b></div><div><span>Stock value</span><b>${esc(moneyShort(c.value))}</b></div><div><span>Movements today</span><b>${fmtNum(v.moves, 0)}</b></div></div>`;
    };
    v.body = b => {
        v.drawChips();
        if (mode === 'manager' && v.tab === 'log') return drawLog(b);
        if (v.loading && !v.items.length) { b.innerHTML = (mode === 'manager' ? statsStrip() : '') + loadingHtml(); return; }
        if (v.error && !v.items.length) { b.innerHTML = errorHtml(v.error); return; }
        const more = v.page < v.pages ? `<button type="button" class="btn more" data-act="more"${v.loading ? ' disabled' : ''}>${v.loading ? '<span class="spin"></span>Loading…' : `Show more · ${fmtNum(v.total - v.items.length, 0)} left`}</button>` : '';
        b.innerHTML = (mode === 'manager' ? statsStrip() : '') + (v.items.length ? v.items.map(p => stockRow(p, v)).join('') + more
            : `<div class="empty"><b>${mode === 'out' && !v.q ? 'কোনো product stock out নেই' : 'কিছু পাওয়া যায়নি'}</b>${v.q ? 'অন্য নাম বা SKU দিয়ে খুঁজুন' : ''}</div>`);
    };
    const drawLog = b => {
        const L = v.log;
        if (!L || (L.loading && !L.items.length)) { b.innerHTML = loadingHtml(); return; }
        if (L.error && !L.items.length) { b.innerHTML = errorHtml(L.error); return; }
        b.innerHTML = L.items.length ? `<ol class="log">${L.items.map(x => { const d = (x.to ?? 0) - (x.from ?? 0); return `<li><div class="lg-main"><b>${esc(x.product)}</b><span class="lg-meta">${when(x.time * 1000)} · ${esc(x.reason)}${x.user ? ' · by ' + esc(x.user) : ''}</span></div><div class="lg-num"><span>${x.from === null ? 'not tracked' : qtyFmt(x.from)} → ${x.to === null ? '—' : qtyFmt(x.to)}</span><b class="${d >= 0 ? 'good' : 'bad'}">${d > 0 ? '+' : ''}${qtyFmt(d)}</b></div></li>`; }).join('')}</ol>${L.items.length < L.total ? `<button type="button" class="btn more" data-act="logmore">Show more · ${fmtNum(L.total - L.items.length, 0)} left</button>` : ''}`
            : '<div class="empty"><b>No stock movements yet</b>Stock update বা order হলে এখানে দেখাবে</div>';
    };
    v.foot = f => {
        if (mode === 'all') { f.innerHTML = `<div class="foot-row"><span>এখানে শুধু দেখা যায়। Qty update করতে:</span><button type="button" class="btn btn-primary" data-act="go-mgr">${ICON.sliders}Open Stock Manager</button></div>`; return !!C.canStock; }
        const n = v.staged.size; if (!n) return false;
        const d = [...v.staged].reduce((s, [id, q]) => { const p = v.find(id); return s + q - Number(p && p.stock_qty !== '' ? p.stock_qty : 0); }, 0);
        f.innerHTML = `<div class="savebar"><div class="sb-info"><b>${n} change${n > 1 ? 's' : ''}</b><span class="${d >= 0 ? 'good' : 'bad'}">${d >= 0 ? '+' : ''}${qtyFmt(d)} units</span></div>
            ${mode === 'manager' ? `<select id="rar-reason" aria-label="Reason for change">${REASONS.map(r => `<option${r === v.reason ? ' selected' : ''}>${esc(r)}</option>`).join('')}</select>` : ''}
            <button type="button" class="btn" data-act="discard">Discard</button><button type="button" class="btn btn-primary" data-act="save-all"${v.saving ? ' disabled' : ''}>${v.saving ? '<span class="spin"></span>' : ICON.ok}Save${n > 1 ? ' all' : ''}</button></div>`;
        return true;
    };
    const stage = (p, q, fromInput) => {
        q = Math.max(0, Math.min(9999999, Math.floor(Number(q)) || 0));
        if (p.stock_qty !== '' && q === Number(p.stock_qty)) v.staged.delete(p.id); else v.staged.set(p.id, q);
        v.confirmClose = false; patchStockRow(v, p, fromInput); drawFoot();
    };
    const replace = prod => { const i = v.items.findIndex(x => x.id === prod.id); if (i >= 0) v.items[i] = prod; };
    const commitOne = async p => {
        const to = v.staged.get(p.id); if (to === undefined) return;
        const from = p.stock_qty, row = E.body.querySelector(`.srow[data-pid="${p.id}"]`);
        row && row.classList.add('saving');
        try {
            const res = await api('stock_update', { product_id:p.id, qty:to, reason:mode === 'manager' ? v.reason : 'Quick update' });
            v.staged.delete(p.id); replace(res.product); drawBody(false);
            toast(`<b>${esc(shortName(p.name))}</b>: ${from === '' ? 'not tracked' : qtyFmt(from)} → ${qtyFmt(to)}`, from === '' ? {} : { action:'Undo', fn:async () => { try { const back = await api('stock_update', { product_id:p.id, qty:from, reason:'Undo' }); replace(back.product); if (isTop(v)) drawBody(false); } catch (e) { fail(e); } } });
        } catch (e) { row && row.classList.remove('saving'); fail(e); }
    };
    const commitAll = async () => {
        if (v.saving) return;
        const items = [...v.staged].map(([id, qty]) => ({ id, qty })), prev = items.map(({ id }) => ({ id, qty:(v.find(id) || {}).stock_qty }));
        v.saving = true; drawFoot();
        try {
            const res = await api('stock_bulk', { items, reason:mode === 'manager' ? v.reason : 'Quick update' });
            res.updated.forEach(p => { replace(p); v.staged.delete(p.id); });
            v.confirmClose = false; v.moves += res.updated.length;
            drawBody(false);
            if (res.errors && res.errors.length) toast(esc(res.errors.map(x => x.message).join(' · ')), { error:true });
            const undoable = prev.filter(x => x.qty !== '' && x.qty !== undefined);
            toast(`${toBn(res.updated.length)}টি product-এর stock update হয়েছে`, undoable.length ? { action:'Undo', fn:async () => { try { const back = await api('stock_bulk', { items:undoable, reason:'Undo' }); back.updated.forEach(replace); if (isTop(v)) drawBody(false); } catch (e) { fail(e); } } } : {});
        } catch (e) { fail(e); }
        v.saving = false; drawFoot();
    };
    v.on_body_click = e => {
        const a = e.target.closest('[data-act]'); if (!a) return;
        if (a.dataset.act === 'more' && !v.loading) { v.page++; v.loading = true; drawBody(false); v.fetch(false); return; }
        if (a.dataset.act === 'logmore') { v.log.page++; v.fetchLog(false); return; }
        if (a.dataset.act === 'retry') { v.fetch(true); return; }
        const row = e.target.closest('.srow'); if (!row) return;
        const p = v.find(+row.dataset.pid); if (!p) return;
        const q = Number(v.shown(p) === '' ? 0 : v.shown(p)), act = a.dataset.act;
        if (act === 'dec') stage(p, q - 1); else if (act === 'inc') stage(p, q + 1);
        else if (act === 'add5') stage(p, q + 5); else if (act === 'add10') stage(p, q + 10);
        else if (act === 'reset') { v.staged.delete(p.id); patchStockRow(v, p); drawFoot(); }
        else if (act === 'save') commitOne(p);
    };
    v.on_body_input = e => {
        const inp = e.target.closest('input[data-act="qty"]'); if (!inp) return;
        const p = v.find(+inp.closest('.srow').dataset.pid), raw = fromBn(inp.value).replace(/\D/g, '');
        if (raw !== inp.value) inp.value = raw;
        if (raw === '') { v.staged.delete(p.id); patchStockRow(v, p, true); drawFoot(); return; }
        stage(p, parseInt(raw, 10), true);
    };
    v.on_body_focusout = e => { const inp = e.target.closest('input[data-act="qty"]'); if (inp && inp.value === '') { const p = v.find(+inp.closest('.srow').dataset.pid); inp.value = v.shown(p); } };
    v.on_body_keydown = e => {
        const inp = e.target.closest('input[data-act="qty"]'); if (!inp || e.key !== 'Enter') return;
        e.preventDefault(); const p = v.find(+inp.closest('.srow').dataset.pid);
        if (mode === 'manager') inp.blur(); else commitOne(p);
    };
    let timer;
    v.on_tools_input = e => { if (e.target.type === 'search') { v.q = e.target.value; clearTimeout(timer); timer = setTimeout(() => v.fetch(true), 320); } };
    v.on_tools_change = e => { if (e.target.id === 'scat') v.cat = +e.target.value; if (e.target.id === 'ssort') v.sort = e.target.value; if (e.target.id === 'scat' || e.target.id === 'ssort') v.fetch(true); };
    v.on_tools_click = e => {
        const c = e.target.closest('[data-lv]'); if (c && c.dataset.lv !== v.chip) { v.chip = c.dataset.lv; v.fetch(true); return; }
        const t = e.target.closest('[data-tab]'); if (t && t.dataset.tab !== v.tab) { v.tab = t.dataset.tab; v.q = ''; drawView(true); v.fetch(true); }
    };
    v.on_foot_change = e => { if (e.target.id === 'rar-reason') v.reason = e.target.value; };
    v.on_foot_click = e => {
        const a = e.target.closest('[data-act]'); if (!a) return;
        if (a.dataset.act === 'go-mgr') openView(stockView('manager'), true);
        else if (a.dataset.act === 'discard') { v.staged.clear(); drawBody(false); }
        else if (a.dataset.act === 'save-all') commitAll();
    };
    return v;
}
function deltaTxt(p, q) {
    const cur = p.stock_qty === '' ? null : Number(p.stock_qty);
    if (q === '' || q === undefined) return cur === null ? 'not tracked yet' : `value ${esc(money(cur * p.price))}`;
    const d = q - (cur ?? 0);
    if (cur !== null && d === 0) return `value ${esc(money(q * p.price))}`;
    return `${cur === null ? 'not tracked' : qtyFmt(cur)} → ${qtyFmt(q)}<b class="${d >= 0 ? 'good' : 'bad'}">${d > 0 ? '+' : ''}${qtyFmt(d)}</b>`;
}
function stockRow(p, v) {
    const q = v.shown(p), lv = levelOf(p, q), changed = v.staged.has(p.id);
    const meta = `<span class="lvl lv-${lv}">${LVLABEL[lv]}</span>${p.sku ? `<span class="mono">${esc(p.sku)}</span>` : ''}${p.category ? `<span>${esc(p.category)}</span>` : ''}<span>${esc(money(p.price))}</span>${p.updated ? `<span>updated ${ago(p.updated * 1000)}</span>` : ''}`;
    const img = `<img src="${esc(p.image)}" alt="" loading="lazy">`;
    if (!v.editable) return `<div class="srow ro lv-${lv}" data-pid="${p.id}">${img}<div class="s-main"><div class="s-name">${esc(p.name)}</div><div class="s-meta">${meta}</div></div><div class="s-qty-ro"><b>${q === '' ? '—' : qtyFmt(q)}</b><small>${q === '' ? 'not tracked' : 'units'}</small></div></div>`;
    return `<div class="srow lv-${lv}${changed ? ' changed' : ''}" data-pid="${p.id}">${img}
        <div class="s-main"><div class="s-name">${esc(p.name)}</div><div class="s-meta">${meta}</div></div>
        <div class="s-qty"><div class="stepper"><button type="button" data-act="dec" aria-label="Decrease ${esc(p.name)}">${ICON.minus}</button><input type="text" inputmode="numeric" id="q-${v.mode}-${p.id}" data-act="qty" value="${q === '' ? '' : q}" placeholder="—" aria-label="Quantity of ${esc(p.name)}"><button type="button" data-act="inc" aria-label="Increase ${esc(p.name)}">${ICON.plus}</button></div>
            <span class="quick-add"><button type="button" data-act="add5">+5</button><button type="button" data-act="add10">+10</button></span></div>
        <div class="s-act"><span class="s-delta${changed ? '' : ' idle'}">${deltaTxt(p, q)}</span>
            <button type="button" class="btn btn-sm btn-ghost" data-act="reset"${changed ? '' : ' hidden'}>Reset</button>
            ${v.mode !== 'manager' ? `<button type="button" class="btn btn-sm btn-primary" data-act="save"${changed ? '' : ' hidden'}>${p.stock_qty === '' ? 'Set stock' : 'Save'}</button>` : ''}</div>
    </div>`;
}
function patchStockRow(v, p, fromInput) {
    const row = E.body.querySelector(`.srow[data-pid="${p.id}"]`); if (!row) return;
    const q = v.shown(p), lv = levelOf(p, q), changed = v.staged.has(p.id);
    row.className = `srow lv-${lv}${changed ? ' changed' : ''}`;
    const pl = row.querySelector('.lvl'); pl.className = `lvl lv-${lv}`; pl.textContent = LVLABEL[lv];
    if (!fromInput) row.querySelector('input[data-act="qty"]').value = q === '' ? '' : q;
    const de = row.querySelector('.s-delta'); de.innerHTML = deltaTxt(p, q); de.classList.toggle('idle', !changed);
    row.querySelectorAll('[data-act="save"],[data-act="reset"]').forEach(b => { b.hidden = !changed; });
}

/* ================================================================
   Create Order
   ================================================================ */
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
function normPhone(s) { let d = fromBn(s).replace(/\D/g, ''); if (d.length === 13 && d.startsWith('880')) d = d.slice(2); return d; }
function phoneErr(d) {
    if (!d) return 'Contact number দিন';
    if (d.length !== 11) return `+88-এর পরে 11 digit লাগবে — এখন ${toBn(d.length)} digit`;
    if (!/^01[3-9]\d{8}$/.test(d)) return 'Number 013 থেকে 019 দিয়ে শুরু হতে হবে (যেমন 017XXXXXXXX)';
    return '';
}
const newReqId = () => (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'req-' + Date.now() + '-' + Math.random().toString(16).slice(2);
const shortProduct = p => esc(p.name);
function createView() {
    const blank = () => ({ name:'', phone:'', email:'', address:'', district:'', city:'', pay:'cod', discType:'amount', discount:'', ship:String(shipFor('')), shipAuto:true, note:'', items:[] });
    const v = { title:'Create Order', hue:'--c-sales', icon:'plus', f:blank(), touched:{}, tried:false, done:null, saving:false, reqId:'', serverError:'', opened:Date.now(),
        cb:{ district:{ q:'', open:false, active:-1, list:[] }, city:{ q:'', open:false, active:-1, list:[] } },
        pk:{ open:false, q:'', items:[], page:1, pages:1, loading:false, req:0 }, customer:null, lookupReq:0 };
    const $b = s => E.body.querySelector(s);
    const FIELDS = ['name', 'phone', 'email', 'address', 'district', 'city', 'items'];
    const changed = () => { v.reqId = ''; v.serverError = ''; };
    v.dirty = () => !v.done && !!(v.f.name || v.f.phone || v.f.address || v.f.items.length);
    v.dirtyMsg = () => 'Order এখনো save হয়নি — লেখা তথ্য মুছে যাবে';
    v.sub = () => v.done ? `Order #${v.done.number} saved · slip ready` : 'Phone, Facebook, WhatsApp বা walk-in order';
    const product = pid => v.f.items.find(i => i.pid === pid);
    const errs = () => {
        const f = v.f, e = {};
        if (f.name.trim().length < 3) e.name = 'Customer-এর পুরো নাম লিখুন (কমপক্ষে ৩ অক্ষর)';
        const pe = phoneErr(f.phone); if (pe) e.phone = pe;
        if (f.email.trim() && !EMAIL.test(f.email.trim())) e.email = 'সঠিক email দিন (যেমন name@gmail.com)';
        if (f.address.trim().length < 10) e.address = 'বাড়ি/ফ্ল্যাট, রোড, এলাকা সহ পুরো ঠিকানা দিন';
        if (!DISTRICTS.some(d => d.name === f.district)) e.district = 'List থেকে District select করুন';
        if (!f.city.trim()) e.city = f.district ? 'Town / City select করুন' : 'আগে District, তারপর Town / City';
        if (!f.items.length) e.items = 'কমপক্ষে ১টি product add করুন';
        else if (f.items.some(i => i.max !== null && !i.backorders && i.qty > i.max)) e.items = 'কিছু product-এর qty stock-এর চেয়ে বেশি — কমিয়ে দিন';
        else if (f.items.some(i => i.qty < 1)) e.items = 'Qty কমপক্ষে ১ হতে হবে';
        return e;
    };
    const show = field => {
        const msg = errs()[field] || '';
        if (field === 'items') { const m = $b('#itemsMsg'); if (m) { m.textContent = msg; m.hidden = !msg; } return; }
        const fld = $b(`.fld[data-f="${field}"]`); if (!fld) return;
        fld.classList.toggle('err', !!msg); fld.classList.toggle('ok', !msg && !!String(v.f[field] || '').trim());
        const m = fld.querySelector('.msg'); m.textContent = msg; m.hidden = !msg;
    };
    const live = field => { if (v.touched[field] || v.tried || (field === 'phone' && !phoneErr(v.f.phone))) show(field); };
    const calc = () => {
        const f = v.f, sub = sum(f.items, i => i.qty * i.price), raw = parseFloat(fromBn(f.discount).replace(/[^\d.]/g, '') || '0') || 0;
        const d = f.discType === 'percent' ? Math.round(sub * Math.min(raw, 100) / 100 * 100) / 100 : Math.min(raw, sub);
        const ship = parseFloat(f.ship || '0') || 0;
        return { sub, d, ship, tot:Math.max(0, sub - d + ship), units:sum(f.items, i => i.qty) };
    };
    const updateTotals = () => {
        const c = calc(), set = (s, txt) => { const n = $b(s); if (n) n.textContent = txt; };
        set('#tSub', money(c.sub)); set('#tDisc', (c.d ? '−' : '') + money(c.d)); set('#tShip', money(c.ship)); set('#tTot', money(c.tot)); set('#tWords', inWords(c.tot));
        const h = $b('#shipHint');
        if (h) h.innerHTML = v.f.shipAuto ? (v.f.district ? `Auto: ${shipLabel(v.f.district)} ${esc(money(shipFor(v.f.district)))} — প্রয়োজনে বদলাতে পারবেন` : 'District দিলে shipping নিজে বসবে') : `Manual shipping <button type="button" data-act="ship-auto">Auto-তে ফেরত নিন</button>`;
        const tt = E.foot.querySelector('#coTotal'); if (tt) tt.textContent = money(c.tot);
        const n = E.foot.querySelector('#coCount'); if (n) n.textContent = c.units ? `${qtyFmt(c.units)} unit${c.units > 1 ? 's' : ''} · ${v.f.items.length} item${v.f.items.length > 1 ? 's' : ''}` : 'No items yet';
    };
    const phoneUI = () => {
        const d = v.f.phone, cnt = $b('#phCnt'), ret = $b('#retHint');
        if (cnt) { cnt.textContent = `${d.length}/11`; cnt.classList.toggle('ok', !phoneErr(d)); }
        if (!ret) return;
        const c = v.customer;
        ret.hidden = !(c && c.found && c.phone === d);
        if (!ret.hidden) ret.innerHTML = `<span><b>Returning customer</b> · ${c.count} order${c.count > 1 ? 's' : ''} · last ${md(c.last * 1000)} · ${esc(c.name)}</span><button type="button" data-act="fill">Use saved details</button>`;
    };
    const lookup = async () => {
        const d = v.f.phone; if (phoneErr(d)) return;
        const my = ++v.lookupReq;
        try { const res = await api('customer', { phone:d }); if (my !== v.lookupReq || v.f.phone !== d) return; v.customer = Object.assign({ phone:d }, res); phoneUI(); } catch (e) { /* lookup is a convenience */ }
    };

    /* searchable dropdowns */
    const COMBO = {
        district:{ input:'#coDist', opts:() => DISTRICTS.map(d => ({ v:d.name, sub:d.div ? d.div + ' Division' : '' })), custom:false },
        city:{ input:'#coCity', opts:() => townsFor(v.f.district).map(tn => ({ v:tn, sub:v.f.district })), custom:true },
    };
    const comboFilter = k => {
        const c = v.cb[k], q = (c.q || '').trim().toLowerCase(), L = COMBO[k].opts();
        if (!q || c.showAll) return L;
        return L.filter(o => o.v.toLowerCase().includes(q)).sort((a, b) => (b.v.toLowerCase().startsWith(q) - a.v.toLowerCase().startsWith(q)) || a.v.localeCompare(b.v));
    };
    const comboDraw = k => {
        const c = v.cb[k], ul = $b('#cbl-' + k), inp = $b(COMBO[k].input); if (!ul || !inp) return;
        if (!c.open) { ul.hidden = true; inp.setAttribute('aria-expanded', 'false'); return; }
        const L = comboFilter(k); c.list = L;
        let h = L.map((o, i) => `<li role="option" id="${k}-o${i}" data-ci="${i}" aria-selected="${i === c.active}" class="${i === c.active ? 'act' : ''}${o.v === v.f[k] ? ' sel' : ''}"><span>${esc(o.v)}</span><small>${esc(o.sub)}</small></li>`).join('');
        const q = (c.q || '').trim();
        if (COMBO[k].custom && q && !L.some(o => o.v.toLowerCase() === q.toLowerCase())) h += `<li role="option" data-custom="1" class="custom"><span>Use “${esc(q)}”</span><small>list-এ না থাকলে</small></li>`;
        ul.innerHTML = h || `<li class="none">${k === 'city' && !v.f.district ? 'আগে District select করুন' : 'কিছু পাওয়া যায়নি'}</li>`;
        ul.hidden = false; inp.setAttribute('aria-expanded', 'true');
        const act = ul.querySelector('.act'); if (act) act.scrollIntoView({ block:'nearest' });
    };
    const comboOpen = (k, showAll) => { const c = v.cb[k]; c.open = true; c.showAll = !!showAll; c.active = -1; comboDraw(k); };
    const onDistrict = () => {
        const ci = $b('#coCity'), towns = townsFor(v.f.district);
        if (v.f.city && towns.length && !towns.includes(v.f.city) && !v.f.cityCustom) { v.f.city = ''; v.cb.city.q = ''; if (ci) ci.value = ''; }
        if (ci) { ci.disabled = !v.f.district; ci.placeholder = v.f.district ? `Search in ${v.f.district}…` : 'আগে District select করুন'; }
        if (v.f.shipAuto) { v.f.ship = String(shipFor(v.f.district)); const s = $b('#coShip'); if (s) s.value = v.f.ship; }
        updateTotals();
    };
    const pickCombo = (k, val, fromCommit) => {
        const prev = v.f[k], c = v.cb[k];
        v.f[k] = val; c.q = val; c.open = false; c.active = -1;
        if (k === 'city') v.f.cityCustom = !townsFor(v.f.district).includes(val);
        const inp = $b(COMBO[k].input); if (inp) inp.value = val;
        comboDraw(k); v.touched[k] = true; show(k); changed();
        if (k === 'district' && prev !== val) { v.f.cityCustom = false; onDistrict(); }
        if (k === 'district' && !fromCommit && val) setTimeout(() => { const ci = $b('#coCity'); if (ci && !ci.disabled) ci.focus(); }, 0);
        if (k === 'city' || (k === 'district' && prev !== val)) show('city');
    };
    const comboClose = (k, commit) => {
        const c = v.cb[k]; if (!c.open) return; c.open = false;
        if (commit) {
            const q = (c.q || '').trim(), m = COMBO[k].opts().find(o => o.v.toLowerCase() === q.toLowerCase());
            if (m) { pickCombo(k, m.v, true); return; }
            if (q && COMBO[k].custom) { pickCombo(k, q, true); return; }
            const prev = v.f[k]; v.f[k] = ''; if (k === 'district' && prev) onDistrict();
        }
        comboDraw(k); v.touched[k] = true; show(k);
    };
    const comboHtml = (k, id, label, ph, val, disabled) => `<div class="fld" data-f="${k}" data-combo="${k}"><label for="${id}">${label} <span class="req">*</span></label><div class="cb"><input type="text" id="${id}" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="cbl-${k}" autocomplete="off" placeholder="${esc(ph)}" value="${esc(val)}"${disabled ? ' disabled' : ''}><span class="cb-chev" aria-hidden="true"></span><ul class="cb-list" id="cbl-${k}" role="listbox" aria-label="${label}" hidden></ul></div><p class="msg" hidden></p></div>`;

    /* product search (server, colour-coded) */
    const pkFetch = async reset => {
        const my = ++v.pk.req;
        if (reset) { v.pk.page = 1; v.pk.loading = true; pkDraw(); }
        try {
            const res = await api('stock_list', { level:'all', search:v.pk.q.trim(), sort:'name', page:v.pk.page, per_page:30 });
            if (my !== v.pk.req) return;
            v.pk.items = reset ? res.items : v.pk.items.concat(res.items);
            v.pk.pages = res.pages; v.pk.error = '';
        } catch (e) { if (my !== v.pk.req) return; v.pk.error = e.message; }
        v.pk.loading = false; pkDraw();
    };
    const pkDraw = () => {
        const panel = $b('#pkPanel'), list = $b('#pkList'), inp = $b('#coPk'); if (!panel) return;
        panel.hidden = !v.pk.open; if (inp) inp.setAttribute('aria-expanded', String(v.pk.open));
        if (!v.pk.open) return;
        if (v.pk.loading && !v.pk.items.length) { list.innerHTML = loadingHtml('Products আনছি…'); return; }
        if (v.pk.error && !v.pk.items.length) { list.innerHTML = `<div class="empty" style="padding:20px">${esc(v.pk.error)}</div>`; return; }
        list.innerHTML = v.pk.items.length ? v.pk.items.map(p => {
            const lv = p.level, inC = product(p.id), blocked = lv === 'out' && !p.backorders;
            return `<button type="button" class="pk-row lv-${lv}" data-add="${p.id}"${blocked ? ' disabled' : ''} aria-label="${blocked ? 'Stock out: ' : 'Add '}${esc(p.name)}"><img src="${esc(p.image)}" alt="" loading="lazy"><span class="pk-main"><b>${esc(p.name)}</b><small>${p.sku ? `<span class="mono">${esc(p.sku)}</span> · ` : ''}${esc(money(p.price))}${inC ? ` · <em>${qtyFmt(inC.qty)} added</em>` : ''}${blocked ? ' · <em class="bad">add করা যাবে না</em>' : ''}</small></span><span class="pk-stock"><b>${p.stock_qty === '' ? '—' : qtyFmt(p.stock_qty)}</b><small>${lv === 'out' ? 'stock out' : p.stock_qty === '' ? 'not tracked' : 'in stock'}</small></span><span class="pk-add">${blocked ? ICON.ban : ICON.plus}</span></button>`;
        }).join('') + (v.pk.page < v.pk.pages ? `<div class="pk-more"><button type="button" class="btn btn-sm" data-act="pk-more">${v.pk.loading ? '<span class="spin"></span>' : ''}Show more products</button></div>` : '')
            : '<div class="empty" style="padding:24px 12px">কোনো product পাওয়া যায়নি</div>';
    };
    const renderItems = () => {
        const box = $b('#coItems'); if (!box) return;
        box.innerHTML = v.f.items.length ? `<div class="items"><div class="it-head" aria-hidden="true"><span>Sl</span><span>Item</span><span>Qty</span><span class="r">Amount</span><span></span></div>${v.f.items.map((i, n) => {
            const over = i.max !== null && !i.backorders && i.qty > i.max;
            return `<div class="it${over ? ' over' : ''}" data-pid="${i.pid}"><span class="it-sl">${n + 1}</span>
                <div class="it-main"><b>${esc(i.name)}</b><span class="it-meta">${C.allowPrice ? `<label class="sr" for="ip-${i.pid}">Rate</label><input type="text" inputmode="decimal" id="ip-${i.pid}" data-act="iprice" value="${esc(String(i.price))}">each` : `${esc(money(i.price))} each`}<span>· stock ${i.max === null ? 'not tracked' : qtyFmt(i.max)}</span>${over ? `<em>— stock-এ আছে মাত্র ${toBn(i.max)}টি</em>` : ''}</span></div>
                <div class="stepper sm"><button type="button" data-act="idec" aria-label="Decrease">${ICON.minus}</button><input type="text" inputmode="numeric" id="iq-${i.pid}" data-act="iqty" value="${i.qty}" aria-label="Quantity of ${esc(i.name)}"><button type="button" data-act="iinc" aria-label="Increase">${ICON.plus}</button></div>
                <b class="it-line">${esc(money(i.qty * i.price))}</b><button type="button" class="icon-btn sm" data-act="irm" aria-label="Remove ${esc(i.name)}">${ICON.trash}</button></div>`;
        }).join('')}</div>` : '<p class="it-empty">উপরের <b>Search products</b>-এ চাপ দিয়ে product add করুন</p>';
    };
    const addItem = pid => {
        const p = v.pk.items.find(x => x.id === pid); if (!p) return;
        if (p.level === 'out' && !p.backorders) return;
        const it = product(pid), max = p.stock_qty === '' ? null : Number(p.stock_qty);
        if (it) {
            if (it.max !== null && !it.backorders && it.qty >= it.max) { toast(`${esc(shortName(p.name))} — stock-এ আছে মাত্র ${toBn(it.max)}টি`); return; }
            it.qty++;
        } else v.f.items.push({ pid, name:p.name, price:Number(p.price), qty:1, max, backorders:!!p.backorders });
        changed(); renderItems(); pkDraw(); updateTotals(); if (v.tried) show('items');
    };

    v.body = b => {
        if (v.done) {
            const o = v.done;
            b.innerHTML = `<div class="saved"><div class="saved-head"><div class="done-ico">${ICON.ok}</div><div><h3>Order #${esc(o.number)} ${v.duplicate ? 'already saved' : 'saved'}</h3><p>${esc(o.name)} · +88 ${esc(String(o.phone).replace(/^\+?88/, ''))} · <b>${esc(money(o.total))}</b> · ${esc(stLabel(o.status))}</p></div></div>${slipBlock(o)}
                <div class="done-acts"><button type="button" class="btn" data-act="view">View order</button><button type="button" class="btn btn-primary" data-act="again">${ICON.plus}Create another order</button></div></div>`;
            fillSlip(o); return;
        }
        const f = v.f, now = Date.now();
        v.cb.district.q = f.district; v.cb.city.q = f.city; v.cb.district.open = v.cb.city.open = false;
        b.innerHTML = `${v.serverError ? `<p class="form-error" role="alert">${esc(v.serverError)}</p>` : ''}<form class="co" id="coForm" novalidate>
            <div class="co-meta"><div><span>Order No</span><b>Auto</b><small>save হলে নম্বর বসবে</small></div><div><span>Date</span><b>${md(now)}, ${zp(now).y}</b><small>${hm(now)} · auto</small></div><div><span>Created by</span><b>${esc(C.userName || '')}</b><small>${esc(C.roleLabel || '')}</small></div></div>
            <section class="fsec"><h4>Customer Details <small>* চিহ্নিত ঘর বাধ্যতামূলক</small></h4>
                <div class="frow two">
                    <div class="fld" data-f="name"><label for="coName">Name <span class="req">*</span></label><input type="text" id="coName" autocomplete="name" placeholder="Customer's full name" value="${esc(f.name)}"><p class="msg" hidden></p></div>
                    <div class="fld" data-f="phone"><label for="coPhone">Contact No. <span class="req">*</span></label><div class="ph"><span class="ph-pre">+88</span><input type="text" id="coPhone" inputmode="numeric" autocomplete="tel-national" maxlength="17" placeholder="01XXXXXXXXX" value="${esc(f.phone)}"></div><div class="hint"><span>11 digits · 013–019 · বাংলা সংখ্যাও চলবে</span><span class="cnt" id="phCnt">0/11</span></div><p class="msg" hidden></p><div class="returning" id="retHint" hidden></div></div>
                </div>
                <div class="frow"><div class="fld" data-f="email"><label for="coEmail">Email <span class="opt">(optional)</span></label><input type="email" id="coEmail" autocomplete="email" placeholder="name@gmail.com" value="${esc(f.email)}"><div class="hint"><span>দিলে order status ও সব update এই email-এ যাবে</span></div><p class="msg" hidden></p></div></div>
            </section>
            <section class="fsec"><h4>Shipping Details</h4>
                <div class="frow"><div class="fld" data-f="address"><label for="coAddr">Full Address <span class="req">*</span></label><textarea id="coAddr" rows="3" autocomplete="street-address" placeholder="House/Flat no., Road, Block/Sector, Area, nearby landmark">${esc(f.address)}</textarea><p class="msg" hidden></p></div></div>
                <div class="frow two">${comboHtml('district', 'coDist', 'District', 'Search district…', f.district, false)}${comboHtml('city', 'coCity', 'Town / City', f.district ? `Search in ${f.district}…` : 'আগে District select করুন', f.city, !f.district)}</div>
            </section>
            <section class="fsec"><h4>Order Details <small>0 stock product add করা যাবে না</small></h4>
                <div class="picker"><div class="search">${ICON.search}<input type="search" id="coPk" placeholder="Search products — name or SKU" autocomplete="off" aria-controls="pkList" aria-expanded="false" value="${esc(v.pk.q)}"></div>
                    <div class="pk-panel" id="pkPanel" hidden><div class="pk-legend"><span><i class="dot lv-ok"></i>${THRESHOLD}+ in stock</span><span><i class="dot lv-low"></i>Low 1–${THRESHOLD}</span><span><i class="dot lv-out"></i>Stock out</span><button type="button" class="link-btn" data-act="pk-done">Done</button></div><div class="pk-list" id="pkList"></div></div></div>
                <div id="coItems"></div><p class="msg" id="itemsMsg" hidden></p>
                <div class="totals">
                    <div class="tr"><span>Items subtotal</span><b id="tSub">${esc(money(0))}</b></div>
                    <div class="tr tr-in"><label for="coDisc">Discount</label><div class="inl"><div class="unit" role="group" aria-label="Discount type"><button type="button" data-act="dt" data-dt="amount" aria-pressed="${f.discType === 'amount'}">${esc(C.currency || '৳')}</button><button type="button" data-act="dt" data-dt="percent" aria-pressed="${f.discType === 'percent'}">%</button></div><input type="text" id="coDisc" inputmode="decimal" placeholder="0" value="${esc(f.discount)}"></div><b id="tDisc">${esc(money(0))}</b></div>
                    <div class="tr tr-in"><label for="coShip">Shipping</label><div class="inl"><input type="text" id="coShip" inputmode="decimal" placeholder="0" value="${esc(f.ship)}"></div><b id="tShip">${esc(money(0))}</b></div>
                    <p class="ship-hint" id="shipHint"></p>
                    <div class="tr tot"><span>Total</span><b id="tTot">${esc(money(0))}</b></div>
                    <div class="words"><span>In words</span><b id="tWords">Taka Zero Only</b></div>
                </div>
            </section>
            <section class="fsec"><h4>Payment &amp; note</h4>
                <div class="paychips" role="radiogroup" aria-label="Payment method">${Object.entries(C.payments || { cod:'Cash on delivery' }).map(([k, l]) => `<label><input type="radio" name="coPay" id="pay-${esc(k)}" value="${esc(k)}"${k === f.pay ? ' checked' : ''}><span>${esc(l)}</span></label>`).join('')}</div>
                <div class="fld"><label for="coNote">Order note <span class="opt">(optional)</span></label><textarea id="coNote" rows="2" placeholder="যেমন: বিকেল ৫টার পরে দিন">${esc(f.note)}</textarea></div>
            </section></form>`;
        renderItems(); phoneUI(); pkDraw(); updateTotals();
        FIELDS.forEach(k => { if (v.touched[k] || v.tried || (k === 'phone' && f.phone)) show(k); });
    };
    v.foot = ft => {
        if (v.done) return false;
        ft.innerHTML = `<div class="co-foot"><div><span class="k">Order total</span><b id="coTotal">${esc(money(0))}</b><small id="coCount"></small></div><button type="button" class="btn btn-primary btn-lg" data-act="submit"${v.saving ? ' disabled' : ''}>${v.saving ? '<span class="spin"></span>Saving…' : ICON.share + 'Save &amp; Share'}</button></div>`;
        updateTotals(); return true;
    };
    const submit = async () => {
        if (v.saving) return;
        ['district', 'city'].forEach(k => comboClose(k, true));
        v.tried = true;
        const e = errs(); FIELDS.forEach(show);
        const keys = Object.keys(e);
        if (keys.length) {
            const el = $b({ name:'#coName', phone:'#coPhone', email:'#coEmail', address:'#coAddr', district:'#coDist', city:'#coCity', items:'#coPk' }[keys[0]]);
            if (el) { el.scrollIntoView({ block:'center', behavior:'smooth' }); if (!el.disabled && keys[0] !== 'items' && keys[0] !== 'district' && keys[0] !== 'city') el.focus({ preventScroll:true }); }
            toast(`${toBn(keys.length)}টি ঘর ঠিক করুন`, { error:true }); return;
        }
        const f = v.f, c = calc();
        if (!v.reqId) v.reqId = newReqId();
        const payload = { request_id:v.reqId, name:f.name.trim(), phone:f.phone, email:f.email.trim(), address:f.address.trim(), city:f.city.trim(), district:f.district, note:f.note.trim(),
            shipping:c.ship, discount_type:f.discType, discount:parseFloat(fromBn(f.discount).replace(/[^\d.]/g, '') || '0') || 0, payment:f.pay,
            items:f.items.map(i => ({ id:i.pid, qty:i.qty, price:i.price })) };
        v.saving = true; v.serverError = ''; drawFoot();
        try {
            const res = await api('create_order', { payload });
            v.done = res.order; v.duplicate = !!res.duplicate; v.reqId = ''; v.confirmClose = false; v.saving = false;
            drawBody(true); E.body.scrollTop = 0;
            toast(`Order <b>#${esc(res.order.number)}</b> ${res.duplicate ? 'আগেই save হয়েছিল' : 'saved'} · ${esc(money(res.order.total))} · slip ready`);
            loadStats();
        } catch (err) {
            v.saving = false; v.serverError = err.message; drawBody(false); E.body.scrollTop = 0; fail(err);
        }
    };

    v.onOutside = e => {
        ['district', 'city'].forEach(k => { if (v.cb[k].open && !e.target.closest(`[data-combo="${k}"]`)) comboClose(k, true); });
        if (v.pk.open && !e.target.closest('.picker')) { v.pk.open = false; pkDraw(); }
    };
    v.on_body_focusin = e => {
        const t = e.target;
        if (t.id === 'coDist') comboOpen('district', true);
        else if (t.id === 'coCity') comboOpen('city', true);
        else if (t.id === 'coPk') { v.pk.open = true; if (!v.pk.items.length && !v.pk.loading) pkFetch(true); else pkDraw(); }
    };
    let pkTimer;
    v.on_body_input = e => {
        const t = e.target, f = v.f;
        if (t.id === 'coDist' || t.id === 'coCity') { const k = t.id === 'coDist' ? 'district' : 'city', c = v.cb[k]; c.q = t.value; c.open = true; c.showAll = false; c.active = -1; comboDraw(k); return; }
        if (t.id === 'coPk') { v.pk.q = t.value; v.pk.open = true; clearTimeout(pkTimer); pkTimer = setTimeout(() => pkFetch(true), 280); return; }
        changed();
        if (t.id === 'coName') { f.name = t.value; live('name'); }
        else if (t.id === 'coPhone') { const clean = fromBn(t.value).replace(/[^\d+]/g, ''); if (clean !== t.value) t.value = clean; f.phone = normPhone(clean); phoneUI(); live('phone'); if (!phoneErr(f.phone)) lookup(); }
        else if (t.id === 'coEmail') { f.email = t.value; live('email'); }
        else if (t.id === 'coAddr') { f.address = t.value; live('address'); }
        else if (t.id === 'coDisc' || t.id === 'coShip') { const d = fromBn(t.value).replace(/[^\d.]/g, ''); if (d !== t.value) t.value = d; if (t.id === 'coDisc') f.discount = d; else { f.ship = d; f.shipAuto = false; } updateTotals(); }
        else if (t.id === 'coNote') f.note = t.value;
        else if (t.dataset.act === 'iqty' || t.dataset.act === 'iprice') {
            const row = t.closest('.it'), it = product(+row.dataset.pid);
            if (t.dataset.act === 'iqty') { const raw = fromBn(t.value).replace(/\D/g, ''); if (raw !== t.value) t.value = raw; it.qty = parseInt(raw || '0', 10); }
            else { const raw = fromBn(t.value).replace(/[^\d.]/g, ''); if (raw !== t.value) t.value = raw; it.price = parseFloat(raw || '0') || 0; }
            const over = it.max !== null && !it.backorders && it.qty > it.max; row.classList.toggle('over', over);
            row.querySelector('.it-line').textContent = money(it.qty * it.price);
            updateTotals(); if (v.tried) show('items');
        }
    };
    v.on_body_change = e => { if (e.target.name === 'coPay') { v.f.pay = e.target.value; changed(); } };
    v.on_body_keydown = e => {
        const t = e.target;
        if (t.id === 'coDist' || t.id === 'coCity') {
            const k = t.id === 'coDist' ? 'district' : 'city', c = v.cb[k];
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault(); if (!c.open) comboOpen(k, !c.q || c.q === v.f[k]);
                const n = c.list.length; if (n) { c.active = e.key === 'ArrowDown' ? Math.min(n - 1, c.active + 1) : Math.max(0, c.active - 1); comboDraw(k); }
                return;
            }
            if (e.key === 'Enter') { e.preventDefault(); const L = c.list; if (c.open && c.active >= 0 && L[c.active]) pickCombo(k, L[c.active].v); else if (c.open && L.length === 1) pickCombo(k, L[0].v); else comboClose(k, true); return; }
            if (e.key === 'Escape' && c.open) { e.stopPropagation(); comboClose(k, true); }
            return;
        }
        if (t.id === 'coPk') {
            if (e.key === 'Escape' && v.pk.open) { e.stopPropagation(); v.pk.open = false; pkDraw(); }
            if (e.key === 'Enter') { e.preventDefault(); const first = E.body.querySelector('.pk-row:not(:disabled)'); if (first && v.pk.q.trim()) addItem(+first.dataset.add); }
            return;
        }
        if (e.key === 'Enter' && t.tagName === 'INPUT') e.preventDefault();
    };
    v.on_body_focusout = e => {
        const t = e.target, rt = e.relatedTarget;
        if (t.id === 'coDist' || t.id === 'coCity') { const k = t.id === 'coDist' ? 'district' : 'city'; if (rt && !rt.closest(`[data-combo="${k}"]`)) comboClose(k, true); return; }
        if (t.id === 'coPk') { if (rt && !rt.closest('.picker')) { v.pk.open = false; pkDraw(); } return; }
        const k = { coName:'name', coPhone:'phone', coEmail:'email', coAddr:'address' }[t.id];
        if (k) { v.touched[k] = true; show(k); }
        if (t.dataset && t.dataset.act === 'iqty' && t.value === '') { const it = product(+t.closest('.it').dataset.pid); it.qty = 1; renderItems(); updateTotals(); }
    };
    v.on_body_click = e => {
        const li = e.target.closest('.cb-list li');
        if (li) { const k = li.closest('[data-combo]').dataset.combo, c = v.cb[k]; if (li.dataset.custom) pickCombo(k, c.q.trim()); else if (li.dataset.ci != null) pickCombo(k, c.list[+li.dataset.ci].v); return; }
        const add = e.target.closest('[data-add]'); if (add) { addItem(+add.dataset.add); return; }
        if (v.done && slipActions(e, v.done)) return;
        const a = e.target.closest('[data-act]'); if (!a) return;
        const act = a.dataset.act, row = a.closest('.it'), it = row ? product(+row.dataset.pid) : null;
        if (act === 'pk-done') { v.pk.open = false; pkDraw(); }
        else if (act === 'pk-more') { v.pk.page++; pkFetch(false); }
        else if (act === 'dt') { v.f.discType = a.dataset.dt; E.body.querySelectorAll('[data-act="dt"]').forEach(x => x.setAttribute('aria-pressed', String(x === a))); changed(); updateTotals(); }
        else if (act === 'ship-auto') { v.f.shipAuto = true; v.f.ship = String(shipFor(v.f.district)); const s = $b('#coShip'); if (s) s.value = v.f.ship; changed(); updateTotals(); }
        else if (act === 'iinc') { if (it.max !== null && !it.backorders && it.qty >= it.max) { toast(`Stock-এ আছে মাত্র ${toBn(it.max)}টি`); return; } it.qty++; changed(); renderItems(); pkDraw(); updateTotals(); if (v.tried) show('items'); }
        else if (act === 'idec') { it.qty = Math.max(1, it.qty - 1); changed(); renderItems(); pkDraw(); updateTotals(); if (v.tried) show('items'); }
        else if (act === 'irm') { v.f.items = v.f.items.filter(i => i !== it); changed(); renderItems(); pkDraw(); updateTotals(); if (v.tried) show('items'); }
        else if (act === 'fill' && v.customer) {
            const c = v.customer, dist = DISTRICTS.find(d => norm(d.name) === norm(c.district));
            Object.assign(v.f, { name:c.name || v.f.name, email:c.email || v.f.email, address:c.address || v.f.address, district:dist ? dist.name : v.f.district, city:c.city || v.f.city });
            v.f.cityCustom = !townsFor(v.f.district).includes(v.f.city);
            if (v.f.shipAuto) v.f.ship = String(shipFor(v.f.district));
            ['name', 'email', 'address', 'district', 'city'].forEach(k2 => { v.touched[k2] = true; });
            changed(); drawBody(false); toast('আগের order থেকে তথ্য বসানো হয়েছে — মিলিয়ে নিন');
        }
        else if (act === 'view') openView(orderView(v.done.id), true);
        else if (act === 'again') {
            v.f = blank(); v.touched = {}; v.tried = false; v.done = null; v.duplicate = false; v.customer = null; v.pk = { open:false, q:'', items:[], page:1, pages:1, loading:false, req:0 };
            drawView(true); setTimeout(() => { const n = $b('#coName'); if (n) n.focus(); }, 30);
        }
    };
    v.on_foot_click = e => { if (e.target.closest('[data-act="submit"]')) submit(); };
    return v;
}

/* ================================================================
   Routing from dashboard cards
   ================================================================ */
function openKey(key) {
    if (key.startsWith('m-') && !MANAGER) return;
    const L = PLABEL[S.period], salesLine = () => S.stats ? money(S.stats.period.cur.sales) : '';
    const V = {
        'k-orders':() => ordersView({ key, title:L.orders, hue:'--c-orders', icon:'orders', scope:S.period, chips:['all', ...STATUSES] }),
        'k-orders-today':() => ordersView({ key, title:"Today's Orders", hue:'--c-orders', icon:'orders', scope:'today', chips:['all', ...STATUSES] }),
        'k-sales':() => ordersView({ key, title:L.sales, hue:'--c-sales', icon:'taka', scope:S.period, group:'sale', chips:['all', ...STATUSES.filter(s => !isVoid(s))], totalLine:salesLine }),
        'k-done':() => ordersView({ key, title:L.done, hue:'--c-done', icon:'check', scope:S.period, fixed:'completed' }),
        'k-ret':() => ordersView({ key, title:L.ret, hue:'--c-return', icon:'ret', scope:S.period, group:'void', chips:['all', ...STATUSES.filter(isReturn)] }),
        's-all':() => stockView('all'),
        's-live':() => stockView('live'),
        's-out':() => stockView('out'),
        'att-low':() => stockView('live', 'low'),
        'stockmgr':() => stockView('manager'),
        'create':() => createView(),
        'm-all':() => ordersView({ key, title:'All Orders', hue:'--c-allorders', icon:'list', scope:'all', chips:['all', ...STATUSES], dateSel:true }),
        'm-live':() => ordersView({ key, title:'Live Orders', hue:'--c-liveorders', icon:'truck', scope:'all', group:'live', chips:['all', ...STATUSES.filter(s => LIVE.has(s))], oldestFirst:true }),
        'm-proc':() => ordersView({ key, title:'Processing Orders', hue:'--c-processing', icon:'clock', scope:'all', fixed:'processing', oldestFirst:true }),
        'att-stale':() => ordersView({ key, title:'Waiting 24h+', hue:'--c-liveorders', icon:'truck', scope:'stale', group:'live', chips:['all', ...STATUSES.filter(s => LIVE.has(s))], oldestFirst:true }),
        'att-hold':() => ordersView({ key, title:'On hold orders', hue:'--c-processing', icon:'clock', scope:'all', fixed:'on-hold', oldestFirst:true }),
        'att-today-live':() => ordersView({ key, title:"Today's running orders", hue:'--c-orders', icon:'orders', scope:'today', group:'live', chips:['all', ...STATUSES.filter(s => LIVE.has(s))], oldestFirst:true }),
    };
    if (!V[key]) return;
    if ((key.startsWith('k-') || key.startsWith('att-today')) && !canOrders) return;
    if ((key === 'create') && !C.canOrder) return;
    openView(V[key]());
}
const MAIN = $('#rar-main');
if (MAIN) MAIN.addEventListener('click', e => {
    const o = e.target.closest('[data-open-order]'); if (o) { openView(orderView(+o.dataset.openOrder)); return; }
    const b = e.target.closest('[data-open]'); if (b) openKey(b.dataset.open);
});
const PERIOD = $('#rar-period');
if (PERIOD) PERIOD.addEventListener('click', e => {
    const b = e.target.closest('[data-period]'); if (!b || b.dataset.period === S.period) return;
    S.period = b.dataset.period;
    $$('#rar-period button').forEach(x => x.setAttribute('aria-pressed', String(x === b)));
    $('#rar-kpis').innerHTML = '<div class="card skel"></div>'.repeat(4);
    loadStats();
});
const RANGE = $('#rar-range');
if (RANGE) RANGE.addEventListener('click', e => {
    const b = e.target.closest('[data-days]'); if (!b || +b.dataset.days === S.days) return;
    S.days = +b.dataset.days;
    $$('#rar-range button').forEach(x => x.setAttribute('aria-pressed', String(x === b)));
    S.report = null; renderGrowth(); loadReport();
});

let rsz;
addEventListener('resize', () => { clearTimeout(rsz); rsz = setTimeout(() => { renderSales7(); if (S.report) renderGrowth(); }, 200); });

/* ---------------- clock & refresh ---------------- */
let lastMin = -1;
function tick() {
    const n = Date.now(), p = zp(n);
    const clock = $('#rar-clock');
    if (clock) clock.innerHTML = `${WDL[p.wd] || ''} <span class="sep">।</span> ${dmy(n)} <span class="sep">।</span> <b>${hm(n, true)}</b>`;
    const m = Math.floor(n / 60000);
    if (m !== lastMin) {
        lastMin = m;
        const g = p.h >= 5 && p.h < 12 ? 'Good morning' : p.h >= 12 && p.h < 17 ? 'Good afternoon' : p.h >= 17 && p.h < 21 ? 'Good evening' : 'Working late';
        const greet = $('#rar-greet'); if (greet) greet.textContent = `${g}, ${C.userName || ''} · ${C.roleLabel || ''}`;
    }
}
tick(); setInterval(tick, 1000);
setInterval(() => { if (document.visibilityState === 'visible' && E.wrap && E.wrap.hidden) { loadStats(); } }, 60000);
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && E.wrap && E.wrap.hidden) loadStats(); });

/* ---------------- installed app (PWA) ---------------- */
C.nonceAt = Date.now();
let hiddenAt = 0;
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') { hiddenAt = Date.now(); return; }
    // Coming back after a long break: renew the session nonce before the next action.
    if (hiddenAt && Date.now() - C.nonceAt > 30 * 60 * 1000) refreshNonce();
});
addEventListener('pageshow', e => { if (e.persisted) { refreshNonce().then(ok => { if (ok) loadStats(); }); } });
addEventListener('online', () => { toast('Back online.'); loadStats(); });
addEventListener('offline', () => toast('You are offline. Changes will not save until the internet is back.', { error:true, long:true }));

if ('serviceWorker' in navigator && C.swUrl) {
    addEventListener('load', () => {
        navigator.serviceWorker.register(C.swUrl, { scope:new URL(C.staffUrl).pathname, updateViaCache:'none' }).then(reg => { try { reg.update(); } catch (_) {} }).catch(() => {});
    });
}

(function installUi() {
    const tip = $('#rar-install-tip'), btn = $('#rar-install-btn'), how = $('#rar-install-how');
    const standalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
    if (standalone) { if (tip) tip.hidden = true; document.documentElement.classList.add('rar-standalone'); return; }
    const ua = navigator.userAgent || '';
    const ios = /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    if (ios && how) how.innerHTML = 'Safari-তে খুলুন → <b>Share</b> ⎋ → <b>Add to Home Screen</b>.';
    let deferred = null;
    addEventListener('beforeinstallprompt', e => { e.preventDefault(); deferred = e; if (btn) btn.hidden = false; });
    addEventListener('appinstalled', () => { deferred = null; if (tip) tip.hidden = true; toast('App installed. Open it from the home screen.'); });
    if (btn) btn.addEventListener('click', async () => {
        if (!deferred) return;
        deferred.prompt();
        try { await deferred.userChoice; } catch (_) {}
        deferred = null; btn.hidden = true;
    });
})();

/* Home-screen shortcuts: /staff/#create and /staff/#stock */
function openFromHash() {
    const h = (location.hash || '').replace('#', '');
    const map = { create:'create', order:'create', stock:'stockmgr' };
    if (!map[h]) return;
    const b = document.querySelector('[data-open="' + map[h] + '"]');
    if (b) setTimeout(() => b.click(), 60);
    try { history.replaceState(null, '', location.pathname + location.search); } catch (_) {}
}
addEventListener('hashchange', openFromHash);
openFromHash();

loadStats();
loadReport();
})();
