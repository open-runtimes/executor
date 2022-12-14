import axiod from "https://deno.land/x/axiod/mod.ts";

export default async function(req: any, res: any) {
    const payload = JSON.parse(req.payload  === '' ? '{}' : req.payload);

    const todo = (await axiod.get(`https://jsonplaceholder.typicode.com/todos/${payload.id ?? 1}`)).data;

    console.log('Sample Log');

    res.json({
        isTest: true,
        message: 'Hello Open Runtimes 👋',
        variable: req.variables['test-variable'],
        todo
    });
}