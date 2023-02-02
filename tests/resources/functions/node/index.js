const fetch = require("node-fetch");

module.exports = async (req, res) => {
    const payload = JSON.parse(req.payload === '' ? '{}' : req.payload);

    const todo = await fetch(`https://jsonplaceholder.typicode.com/todos/${payload.id ?? 1}`).then(r => r.json());
    console.log('Sample Log');

    res.json({
        isTest: true,
        message: 'Hello Open Runtimes 👋',
        variable: req.variables['test-variable'],
        todo
    });
}