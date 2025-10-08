<<<<<<< HEAD
const counterValueEl = document.querySelector('#counterValue');
const incrementButton = document.querySelector('#incrementButton');

let counter = 0;

const formatNumber = new Intl.NumberFormat('de-DE');

const updateCounter = () => {
  counterValueEl.textContent = formatNumber.format(counter);
};

const increment = () => {
  counter += 1;
  updateCounter();
};

incrementButton.addEventListener('click', increment);

document.addEventListener('keydown', (event) => {
  const isSpace = event.code === 'Space';
  const isEnter = event.code === 'Enter';

  if ((isSpace || isEnter) && !event.repeat) {
    event.preventDefault();
    incrementButton.focus();
    increment();
  }
});

updateCounter();
=======
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
>>>>>>> origin/codex/develop-modern-web-application-with-counter-3hpy2y
