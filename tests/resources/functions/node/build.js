let i = 0;
const interval = setInterval(() => {
  i++;

  if (i >= 10) {
    clearInterval(interval);
  }

  console.log("Step: " + i);
}, 1000);