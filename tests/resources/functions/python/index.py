import json
import requests

def main(req, res):
    payload = json.loads('{}' if not req.payload else req.payload)
    todo_id = payload.get('id', 1)

    var_data = req.variables.get('test-variable', None)

    todo = (requests.get('https://jsonplaceholder.typicode.com/todos/' + str(todo_id))).json()

    print('Sample Log')

    return res.json({
        'isTest': True,
        'message': 'Hello Open Runtimes 👋',
        'todo': todo,
        'variable': var_data
    })