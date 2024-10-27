let i = 0;
const interval = setInterval(() => {
  i++;

  if (i >= 15) {
    clearInterval(interval);
  }

  console.log("Step: " + i);
}, 1000);