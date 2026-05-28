import os

def main(context):
    todo_id = context.req.body.get('id', 1)
    var_data = os.environ.get('TEST_VARIABLE', None)

    context.log('Sample Log')

    return context.res.json({
        'isTest': True,
        'message': 'Hello Open Runtimes 👋',
        'todo': {
            'id': int(todo_id),
            'todo': 'Use a local fixture for executor tests.',
            'completed': False,
            'userId': 13,
        },
        'url': context.req.url,
        'variable': var_data
    })
