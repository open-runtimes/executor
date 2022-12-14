import 'dart:async';
import 'dart:convert';
import 'package:dio/dio.dart' hide Response;

Future<void> start(final req, final res) async {
  final payload = jsonDecode(req.payload == '' ? '{}' : req.payload);

  final id = payload['id'] ?? '1';
  final todo =
      await Dio().get('https://jsonplaceholder.typicode.com/todos/$id');
  print('Sample Log');

  res.json({
    'isTest': true,
    'message': "Hello Open Runtimes 👋",
    'variable': req.variables['test-variable'],
    'todo': todo.data,
  });
}