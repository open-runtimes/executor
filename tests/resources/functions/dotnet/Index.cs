namespace DotNetRuntime;

using System;

public class Handler {
    public async Task<RuntimeOutput> Main(RuntimeContext Context)
    {
        Dictionary<String, Object> Body = (Dictionary<String, Object>) Context.Req.Body;

        string id = Body.TryGetValue("id", out var value) == true ? value.ToString()! : "1";
        var varData = Environment.GetEnvironmentVariable("TEST_VARIABLE") ?? null;

        Context.Log("Sample Log");

        return Context.Res.Json(new()
        {
            { "isTest", true },
            { "message", "Hello Open Runtimes 👋" },
            { "variable", varData },
            { "url", Context.Req.Url },
            { "todo", new Dictionary<string, object>
                {
                    { "id", int.Parse(id) },
                    { "todo", "Use a local fixture for executor tests." },
                    { "completed", false },
                    { "userId", 13 }
                }
            }
        });
    }
}
