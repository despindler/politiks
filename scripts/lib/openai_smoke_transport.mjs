const chunks = [];

for await (const chunk of process.stdin) {
  chunks.push(chunk);
}

try {
  const request = JSON.parse(Buffer.concat(chunks).toString('utf8'));
  const response = await fetch(request.url, {
    method: 'POST',
    headers: request.headers,
    body: request.body,
    redirect: 'error',
    signal: AbortSignal.timeout(request.timeout_seconds * 1000),
  });
  const body = await response.text();
  process.stdout.write(JSON.stringify({ status: response.status, body }));
} catch {
  process.stderr.write('OpenAI smoke transport failed.\n');
  process.exitCode = 1;
}
