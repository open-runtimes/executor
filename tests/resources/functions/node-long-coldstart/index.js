function fibo(n) { 
    if (n < 2)
        return 1;
    else   return fibo(n - 2) + fibo(n - 1);
}

let cache = fibo(45);

module.exports = async (context) => {
    return context.res.send(`cache`);
}