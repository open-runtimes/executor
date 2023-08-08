const fetch = require("node-fetch");

module.exports = async (context)=> {
    const todo = await fetch(`https://jsonplaceholder.typicode.com/todos/${context.req.body.id ?? 1}`).then(r => r.json());
    context.log('Sample Log');

    return context.res.json({
        isTest: true,
        message: 'Hello Open Runtimes 👋',
        url: context.req.url,
        variable: process.env['TEST_VARIABLE'],
        todo
    });
}