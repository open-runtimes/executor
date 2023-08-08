require 'httparty'
require 'json'

def main(req, res)
    payload = JSON.parse(req.payload === '' ? '{}' : req.payload)

    todo = JSON.parse(HTTParty.get("https://jsonplaceholder.typicode.com/todos/" + (payload['id'] || '1')).body)

    puts 'Sample Log'
    
    return res.json({
        'isTest': true,
        'message': 'Hello Open Runtimes 👋',
        'todo': todo,
        'variable': req.variables['test-variable']
    })
end