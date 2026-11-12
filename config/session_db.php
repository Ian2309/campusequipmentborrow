   <?php
   // Stores PHP sessions in the "sessions" table (Supabase) instead of files on the server's disk.
   class DbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface {
       public function __construct(private PDO $pdo) {}

       public function open(string $path, string $name): bool { return true; }
       public function close(): bool { return true; }

       public function read(string $id): string|false {
           $s = $this->pdo->prepare('SELECT data FROM sessions WHERE id = ?');
           $s->execute([$id]);
           $d = $s->fetchColumn();
           return $d === false ? '' : (string)$d;
       }

       public function write(string $id, string $data): bool {
           $this->pdo->prepare('INSERT INTO sessions (id, data, updated_at) VALUES (?, ?, NOW()) ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, updated_at = NOW()')
               ->execute([$id, $data]);
           return true;
       }

       public function destroy(string $id): bool {
           $this->pdo->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);
           return true;
       }

       public function gc(int $max_lifetime): int|false {
           $s = $this->pdo->prepare('DELETE FROM sessions WHERE updated_at < NOW() - make_interval(secs => ?)');
           $s->execute([$max_lifetime]);
           return $s->rowCount();
       }

       // Used by session.use_strict_mode: only accept session IDs that really exist.
       public function validateId(string $id): bool {
           $s = $this->pdo->prepare('SELECT 1 FROM sessions WHERE id = ?');
           $s->execute([$id]);
           return (bool)$s->fetchColumn();
       }

       public function updateTimestamp(string $id, string $data): bool {
           return $this->write($id, $data);
       }
   }