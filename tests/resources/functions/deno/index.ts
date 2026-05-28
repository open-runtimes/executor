export default async function(context: any) {
    context.log('Sample Log');

    return context.res.json({
        isTest: true,
        message: 'Hello Open Runtimes 👋',
        url: context.req.url,
        variable: Deno.env.get("TEST_VARIABLE"),
        todo: {
            id: Number(context.req.body.id ?? 1),
            todo: 'Use a local fixture for executor tests.',
            completed: false,
            userId: 13,
        }
    });
}
