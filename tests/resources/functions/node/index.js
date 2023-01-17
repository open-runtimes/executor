const fetch = require("node-fetch");

module.exports = async (context) => {
    const payload = JSON.parse(context.req.rawBody || '{}');

    const todo = await fetch(`https://jsonplaceholder.typicode.com/todos/${payload.id ?? 1}`).then(r => r.json());
    context.log('Sample Log');
    context.error('Sample Error');

    return context.res.json({
        header: context.req.headers['x-my-header'] ?? 'Missing header',
        isTest: true,
        message: 'Hello Open Runtimes 👋',
        variable: process.env.TEST_VARIABLE ?? 'Missing variable',
        todo
    });
}