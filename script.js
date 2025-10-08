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
