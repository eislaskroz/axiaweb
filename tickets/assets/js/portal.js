function togglePassword() {
    const i=document.getElementById('password');
    if(i)i.type=i.type==='password'?'text':'password';
}
function toggleSidebar() {
    document.querySelector('.sidebar')?.classList.toggle('open');
}
function filterTable() {
    const q=(document.getElementById('tableSearch')?.value||'').toLowerCase();
    document.querySelectorAll('#ticketsTable tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none';});
}
