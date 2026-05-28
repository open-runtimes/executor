#include "RuntimeResponse.h"
#include "RuntimeRequest.h"
#include "RuntimeOutput.h"
#include "RuntimeContext.h"

#include <iostream>
#include <any>
#include <string>

using namespace std;

namespace runtime {
    class Handler {
    public:
        static RuntimeOutput main(RuntimeContext &context)
        {
            RuntimeRequest req = context.req;
            RuntimeResponse res = context.res;

            Json::Value payload = std::any_cast<Json::Value>(req.body);
            std::string id = payload["id"].asString();

            Json::Value todo;
            todo["id"] = stoi(id);
            todo["todo"] = "Use a local fixture for executor tests.";
            todo["completed"] = false;
            todo["userId"] = 13;

            Json::Value response;
            response["isTest"] = true;
            response["message"] = "Hello Open Runtimes 👋";
            response["url"] = req.url;
            response["variable"] = std::getenv("TEST_VARIABLE");
            response["todo"] = todo;

            context.log("Sample Log");

            return res.json(response);
        }
    };
}
