<?php
declare(strict_types=1);
namespace OCA\CallCrm\Service;

/**
 * Pièces jointes des clients : PDF ou photo uniquement, analysées par l'antivirus ClamAV du serveur (clamd, INSTREAM)
 * avant tout enregistrement. Analyse impossible (démon arrêté) = pièce refusée, comme dans gestion.
 */
class Antivirus {
	public const PHOTO_PDF = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif'];
	public const REFUSED = 'Format refusé : seuls les PDF et les photos (JPEG, PNG, WebP, GIF, HEIC) sont acceptés.';
	private const SOCKET = '/run/clamav/clamd.sock';
	private const CHUNK = 65536;

	/** Type réel du contenu, si c'est un PDF ou une photo ; sinon InvalidArgumentException. */
	public static function vet(string $bytes, string $name = 'fichier'): string {
		$mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
		if (!in_array($mime, self::PHOTO_PDF, true)) throw new \InvalidArgumentException(self::REFUSED);
		self::scan($bytes, $name);
		return $mime;
	}

	/** Lève InvalidArgumentException si le fichier est infecté ou ne peut pas être analysé. */
	public static function scan(string $bytes, string $name = 'fichier'): void {
		$s = @stream_socket_client('unix://' . self::SOCKET, $errno, $error, 10);
		if (!$s) throw new \InvalidArgumentException('Analyse antivirus momentanément indisponible : réessayez dans quelques minutes.');
		try {
			stream_set_timeout($s, 60);
			fwrite($s, "zINSTREAM\0");
			for ($i = 0, $n = strlen($bytes); $i < $n; $i += self::CHUNK) {
				$part = substr($bytes, $i, self::CHUNK);
				fwrite($s, pack('N', strlen($part)) . $part);
			}
			fwrite($s, pack('N', 0));
			$reply = rtrim((string)stream_get_contents($s), "\0\n");
		} finally { fclose($s); }
		if (str_ends_with($reply, ' OK')) return;
		if (str_ends_with($reply, ' FOUND')) {
			\OCP\Server::get(\Psr\Log\LoggerInterface::class)->warning('Call CRM : pièce refusée par l’antivirus', ['app' => 'callcrm', 'file' => $name, 'reply' => $reply]);
			throw new \InvalidArgumentException('Fichier refusé : une menace a été détectée par l’antivirus. Il n’a pas été enregistré.');
		}
		throw new \InvalidArgumentException('Analyse antivirus momentanément indisponible : réessayez dans quelques minutes.');
	}
}
