module.exports = async (context) => {
    let log1kb = '';

    for(let i = 0; i < 1024; i++) {
        log1kb += "A";
    }

    // 1MB log
    for(let i = 0; i < 1024; i++) {
        context.log(log1kb);
    }

    return context.res.send('OK');
}