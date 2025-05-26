import axiod from "https://deno.land/x/axiod/mod.ts";

export default async function(context: any) {
    const todo = (await axiod.get(`https://dummyjson.com/todos/${context.req.body.id ?? 1}`)).data;

    context.log('Sample Log');

    return context.res.json({
        isTest: true,
        message: 'Hello Open Runtimes 👋',
        url: context.req.url,
        variable: Deno.env.get("TEST_VARIABLE"),
        todo
    });
}