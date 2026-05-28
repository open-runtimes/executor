module.exports = async (context)=> {
    context.log('Sample Log');

    return context.res.json({
        isTest: true,
        message: 'Hello Open Runtimes 👋',
        url: context.req.url,
        variable: process.env['TEST_VARIABLE'],
        todo: {
            id: Number(context.req.body.id ?? 1),
            todo: 'Use a local fixture for executor tests.',
            completed: false,
            userId: 13,
        }
    });
}
