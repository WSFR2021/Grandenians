// Page navigation
function navigate(page) {
  const content = document.getElementById('main-content');
  content.innerHTML = `
    <div class="dashboard-logo">
      <img src="logo.png" alt="Community School Logo" class="main-logo">
      <h1>Community School</h1>
    </div>
    <h2>${page.charAt(0).toUpperCase() + page.slice(1)}</h2>
    <p>Content for ${page} page.</p>
  `;
}

// Smooth More menu toggle
const moreBtn = document.getElementById('moreBtn');
const moreMenu = document.getElementById('moreMenu');

moreBtn.addEventListener('click', () => {
  moreMenu.classList.toggle('show');
});

// Dark/Light mode toggle
const themeToggle = document.getElementById('themeToggle');
themeToggle.addEventListener('click', () => {
  const body = document.body;
  body.classList.toggle('dark-mode');
  body.classList.toggle('light-mode');
  themeToggle.textContent = body.classList.contains('dark-mode') ? '☀️ Light Mode' : '🌙 Dark Mode';
});

// Logout simulation
function logout() {
  alert('You have been logged out.');
}

// Settings placeholder
function openSettings() {
  alert('Settings page opened.');
}
