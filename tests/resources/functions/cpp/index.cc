#include "RuntimeResponse.h"
#include "RuntimeRequest.h"
#include "RuntimeOutput.h"
#include "RuntimeContext.h"

#include <iostream>
#include <any>
#include <string>
#include <curl/curl.h>

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

            Json::CharReaderBuilder builder;
            Json::CharReader *reader = builder.newCharReader();

            CURL *curl;
            CURLcode curlRes;
            std::string todoBuffer;

            curl = curl_easy_init();
            if (curl)
            {
                std::string url = "https://jsonplaceholder.typicode.com/todos/" + id;
                curl_easy_setopt(curl, CURLOPT_URL, url.c_str());
                curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, WriteCallback);
                curl_easy_setopt(curl, CURLOPT_WRITEDATA, &todoBuffer);
                curlRes = curl_easy_perform(curl);
                curl_easy_cleanup(curl);
            }

            Json::Value todo;
            reader->parse(
                todoBuffer.c_str(),
                todoBuffer.c_str() + todoBuffer.size(),
                &todo,
                nullptr
            );

            delete reader;

            Json::Value response;
            response["isTest"] = true;
            response["message"] = "Hello Open Runtimes 👋";
            response["url"] = req.url;
            response["variable"] = std::getenv("TEST_VARIABLE");
            response["todo"] = todo;

            context.log("Sample Log");

            return res.json(response);
        }

        static size_t WriteCallback(void *contents, size_t size, size_t nmemb, void *userp)
        {
            ((std::string *) userp)->append((char *) contents, size * nmemb);
            return size * nmemb;
        }
    };
}