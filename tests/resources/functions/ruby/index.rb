require 'json'

def main(context)
    context.log('Sample Log')
    
    return context.res.json({
        'isTest': true,
        'message': 'Hello Open Runtimes 👋',
        'todo': {
            'id': (context.req.body['id'] || '1').to_i,
            'todo': 'Use a local fixture for executor tests.',
            'completed': false,
            'userId': 13,
        },
        'url': context.req.url,
        'variable': ENV['TEST_VARIABLE'] || nil,
    })
end
