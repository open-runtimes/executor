require 'httparty'
require 'json'

def main(context)
    todo = JSON.parse(HTTParty.get("https://dummyjson.com/todos/" + (context.req.body['id'] || '1')).body)

    context.log('Sample Log')
    
    return context.res.json({
        'isTest': true,
        'message': 'Hello Open Runtimes 👋',
        'todo': todo,
        'url': context.req.url,
        'variable': ENV['TEST_VARIABLE'] || nil,
    })
end