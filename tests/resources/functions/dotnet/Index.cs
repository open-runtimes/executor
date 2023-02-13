namespace DotNetRuntime;

using System;
using Newtonsoft.Json;

public class Handler {
    static readonly HttpClient http = new();

    public async Task<RuntimeOutput> Main(RuntimeContext Context)
    {
        Dictionary<String, Object> Body = (Dictionary<String, Object>) Context.Req.Body;

        string id = Body.TryGetValue("id", out var value) == true ? value.ToString()! : "1";
        var varData = Environment.GetEnvironmentVariable("TEST_VARIABLE") ?? null;

        var response = await http.GetStringAsync($"https://jsonplaceholder.typicode.com/todos/" + id);
        var todo = JsonConvert.DeserializeObject<Dictionary<string, object>>(response, settings: null);

        Context.Log("Sample Log");

        return Context.Res.Json(new()
        {
            { "isTest", true },
            { "message", "Hello Open Runtimes 👋" },
            { "variable", varData },
            { "url", Context.Req.Url },
            { "todo", todo }
        });
    }
}
