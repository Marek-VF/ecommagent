const counterValueEl = document.querySelector('#counterValue');
const incrementButton = document.querySelector('#incrementButton');

let counter = 0;

const formatNumber = new Intl.NumberFormat('de-DE');

const updateCounter = () => {
  counterValueEl.textContent = formatNumber.format(counter);
};

incrementButton.addEventListener('click', () => {
  counter += 1;
  updateCounter();
});

updateCounter();
