const modal = document.getElementById('modal');
const openModal = document.getElementById('openModal');
const closeModal = document.getElementById('closeModal');

// Abrir modal
openModal.addEventListener('click', () => {
  modal.showModal(); // Usar showModal para abrir como popup
});

// Cerrar modal
closeModal.addEventListener('click', () => {
  modal.close();
});