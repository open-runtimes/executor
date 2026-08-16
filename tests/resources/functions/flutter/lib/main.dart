import 'package:flutter/material.dart';

void main() {
  runApp(const MyApp());
}

class MyApp extends StatelessWidget {
  const MyApp({super.key});

  @override
  Widget build(BuildContext context) {
    const testVar = String.fromEnvironment(
      'TEST_VAR',
      defaultValue: 'not_injected',
    );

    return MaterialApp(
      home: Scaffold(
        body: Text('test_var:$testVar'),
      ),
    );
  }
}
