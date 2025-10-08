const counterDisplay = document.querySelector('[data-counter-value]');
const incrementButton = document.querySelector('[data-increment]');

if (!counterDisplay || !incrementButton) {
  throw new Error('Counter-Markup nicht gefunden.');
}

let value = 0;
const formatter = new Intl.NumberFormat('de-DE');

function animateDisplay() {
  counterDisplay.classList.remove('counter-display--bump');
  window.requestAnimationFrame(() => {
    counterDisplay.classList.add('counter-display--bump');
  });
}

function updateDisplay() {
  counterDisplay.textContent = formatter.format(value);
  animateDisplay();
}

function incrementCounter() {
  value += 1;
  updateDisplay();
}

incrementButton.addEventListener('click', incrementCounter);

incrementButton.addEventListener('keydown', (event) => {
  const isActivationKey = event.key === 'Enter' || event.key === ' ';

  if (isActivationKey && !event.repeat) {
    event.preventDefault();
    incrementCounter();
  }
});

updateDisplay();
