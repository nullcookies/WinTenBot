<?php
/**
 * This file is part of the TelegramBot package.
 *
 * (c) Avtandil Kikabidze aka LONGMAN <akalongman@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Longman\TelegramBot\Commands\SystemCommands;

use GuzzleHttp\Exception\GuzzleException;
use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use WinTenDev\Handlers\ChatHandler;
use WinTenDev\Model\Bot;
use WinTenDev\Model\Fbans;
use WinTenDev\Model\Group;
use WinTenDev\Model\MalFiles;
use WinTenDev\Model\Settings;
use WinTenDev\Model\Tags;
use WinTenDev\Model\UrlLists;
use WinTenDev\Model\Wordlists;
use WinTenDev\Utils\Words;
use WinTenDev\Validators\MessageValidator;

/**
 * Generic message command
 */
class GenericmessageCommand extends SystemCommand
{
	protected $name = 'genericmessage';
	protected $description = 'Handle generic message';
	protected $version = '1.0.0';
	protected $chatHandler;

//	public function __construct(Telegram $telegram, Update $update = null)
//	{
//		parent::__construct($telegram, $update);
//		$this->chatHandler = new ChatHandler($this->getMessage());
//	}
	
	/**
	 * Execute command
	 *
	 * @return bool|ServerResponse
	 * @throws TelegramException
	 * @throws GuzzleException
	 */
	public function execute()
	{
		$pesan = $this->getMessage()->getText();
		$message = $this->getMessage();
		$chatHandler = new ChatHandler($message);
		$from_id = $message->getFrom()->getId();
		$from_first_name = $message->getFrom()->getFirstName();
		$from_last_name = $message->getFrom()->getLastName();
		$from_username = $message->getFrom()->getUsername();
		$chat_id = $message->getChat()->getId();
		$chat_username = $message->getChat()->getUsername();
		$chat_type = $message->getChat()->getType();
		$chat_title = $message->getChat()->getTitle();
		$repMsg = $this->getMessage()->getReplyToMessage();
		
		$chat_setting = Settings::readCache($chat_id);
		$enable_welcome_message = $chat_setting[0]['enable_welcome_message'];
		
		$kata = strtolower($pesan);
		$pesanCmd = explode(' ', strtolower($pesan))[0];
		
		// Validate incoming message..
//		$msgValidator = new MessageValidator($message);
//		$msgValidator->execValidate();
		
		if ($message->getNewChatMembers()) {
			return $this->telegram->executeCommand('newchatmembers');
		}
		
		// Scan BadMessage
		$isBad = $this->checkBadMessage();
		if ($isBad) {
			return $isBad;
		}
		
		// Check if member is banned
		$isBanned = $this->checkFedBan();
		if ($isBanned) {
			return $isBanned;
		}
		
		// Check if this grup is restricted
		$isRestricted = $this->checkRestriction();
		if ($isRestricted) {
			return $isRestricted;
		}
		
		$isNoUsername = $this->checkUsername();
		if ($isNoUsername) {
			return $isNoUsername;
		}
		
		$isForwarded = $this->checkForwardedMessage();

//		$msgValidator->checkForwardedMessage();
//		if ($isForwarded) {
//			return $isForwarded;
//		}
		
		// Command Aliases
		switch ($pesanCmd) {
			case 'ping':
				return $this->telegram->executeCommand('ping');
				break;
			case '@admin':
				return $this->telegram->executeCommand('report');
				break;
		}
		
		// Chatting
		switch (true) {
			case Words::isSameWith($kata, 'gan'):
				$chat = 'ya gan, gimana';
				break;
			case Words::isSameWith($kata, 'mau tanya'):
				$chat = 'Langsung aja tanya gan';
				break;
			case Words::isContain($kata, thanks):
				$chat = 'Sama-sama, senang bisa membantu gan...';
				break;
			case Words::isSameWith($kata, yuk):
				$chat = 'Ayuk, siapa takut 😂';
				break;
			case Words::isContain($pesan, '#'):
				return $this->parseTags();
				break;
			
			default:
				break;
		}
		
		if (isset($chat)) {
			$chatHandler->sendText($chat, '-1');
		}
		
		if ($repMsg !== null) {
			if ($message->getChat()->getType() != 'private') {
				$mssgLink = 'https://t.me/' . $chat_username . '/' . $message->getMessageId();
				$chat = "<a href='tg://user?id=" . $from_id . "'>" . $from_first_name . '</a>' .
					" mereply <a href='" . $mssgLink . "'>pesan kamu" . '</a>' .
					' di grup <b>' . $chat_title . '</b>'
					. "\n" . $message->getText() .
					"\n\nReply pesan ini untuk membalas atau klik tombol <b>Balas</b>";
				$data = [
					'chat_id'                  => $repMsg->getFrom()->getId(),
					'text'                     => $chat,
					'parse_mode'               => 'HTML',
					'disable_web_page_preview' => true,
				];
				
				if ($chat_username != '') {
					$btn_markup = [['text' => '💬 Balas Pesan', 'url' => $mssgLink]];
					$data['reply_markup'] = new InlineKeyboard([
						'inline_keyboard' => array_chunk($btn_markup, 2),
					]);
				}
				
				return Request::sendMessage($data);
			} else {
				$entities = \GuzzleHttp\json_encode($repMsg->getEntities()[1], 128);
				$text = "Wrok " . $entities;
//				$chatHandler->sendText($text);

//				$chat_id = $repMsg->getCaptionEntities()[3]->getUrl();
				$toFName = $repMsg->getEntities()[0]->getUser()->getFirstName();
				$chatUsername = '@' . explode('/', $repMsg->getEntities()[1]->getUrl())[3];
				$messageId = explode('/', $repMsg->getEntities()[1]->getUrl())[4];
				$text .= "\nUsername $chatUsername $messageId";
//				$chatHandler->editText($text);
				
				$getChat = Request::getChat(['chat_id' => $chatUsername]);
//				$chat_id = str_replace('tg://user?id=', '', $chat_id);
				
				if ($getChat->isOk()) {
					$chatId = $getChat->getResult()->id;
				}
				
				if ($message->getText() == null) {
					return $chatHandler->sendText("Saat ini hanya dapat meneruskan balasan pesan teks");
				}
				
				$text = "$from_first_name membalas sebuah pesan\n" . $message->getText();
				
				$data = [
					'chat_id'                  => $chatId,
					'text'                     => $text,
					'reply_to_message_id'      => $messageId,
					'parse_mode'               => 'HTML',
					'disable_web_page_preview' => true,
				];
				
				return Request::sendMessage($data);
			}
		}

//		$pinned_message = $message->getPinnedMessage()->getMessageId();
//		if (isset($pinned_message)) {
//			return $this->telegram->executeCommand('pinnedmessage');
//		}
	}
	
	/**
	 * @return bool
	 */
	private function checkBadMessage()
	{
		$isBad = false;
		$message = $this->getMessage();
		$chatHandler = new ChatHandler($message);
		$textMessage = $message->getCaption() ?? $message->getText();
		$wordScan = Words::clearAlphaNum($textMessage);
		
		if (UrlLists::isContainBadUrl($textMessage)
			|| Wordlists::isContainBadword(strtolower($wordScan))) {
			$chatHandler->deleteMessage();
			$isBad = true;
		}
		
		$file_id = '';
		if ($message->getDocument() != '') {
			$file_id = $message->getDocument()->getFileId();
		} elseif ($message->getPhoto() != '') {
			$file_id = explode('_', $message->getPhoto()[0]->getFileId())[0];
		} elseif ($message->getSticker() != '') {
			$file_id = $message->getSticker()->getFileId();
		} elseif ($message->getVideo() != '') {
			$file_id = $message->getVideo()->getFileId();
		}
		
		if (MalFiles::isMalFile($file_id)) {
			$chatHandler->deleteMessage();
			$isBad = true;
		}
		
		return $isBad;
	}
	
	/**
	 * @return bool
	 * @throws TelegramException
	 * @throws GuzzleException
	 */
	private function checkFedBan()
	{
		$isBanned = false;
		$message = $this->getMessage();
		$chatHandler = new ChatHandler($message);
		$chat_id = $message->getChat()->getId();
		$from_id = $message->getFrom()->getId();
		
		if (Fbans::isBan($from_id) && !$chatHandler->isPrivateChat) {
			$text = "$from_id telah terdeteksi di " . federation_name_short;
			$kickRes = $chatHandler->kickMember($from_id, true);
			if ($kickRes->isOk()) {
				$text .= ' dan berhasil di tendang';
			} else {
				$text .= ' dan gagal di tendang, karena <b>' . $kickRes->getDescription() . '</b>. ' .
					'Pastikan saya Admin dengan level standard';
			}
			$btn_markup = [
				['text' => 'Keluar grup', 'callback_data' => 'group_leave_' . $chat_id],
			];
			$chatHandler->logToChannel($text, $btn_markup);
			$chatHandler->sendText($text, '-1');
			$isBanned = true;
		}

//		if (Cas::checkCas($from_id)) {
//			$text = 'CAS (Combot Anti-Spam)';
//			$chatHandler->logToChannel($text);
//			$chatHandler->kickMember($from_id, true);
//		}
		return $isBanned;
	}
	
	/**
	 * @return bool
	 * @throws TelegramException
	 */
	private function checkRestriction()
	{
		$isRestricted = false;
		$message = $this->getMessage();
		$chat_id = $message->getChat()->getId();
		$chatHandler = new ChatHandler($message);
		
		if (!$chatHandler->isPrivateChat && Group::isMustLeft($message->getChat()->getId())) {
			$text = 'Sepertinya saya salah alamat. Saya pamit dulu..' . "\nGunakan @WinTenBot";
			$chatHandler->logToChannel($text);
			$chatHandler->sendText($text);
			$chatHandler->leaveChat();
			$isRestricted = true;
		}
		return $isRestricted;
	}
	
	/**
	 * @return bool|ServerResponse
	 * @throws TelegramException
	 */
	private function checkUsername()
	{
		$isNoUsername = false;
		$message = $this->getMessage();
		$chatHandler = new ChatHandler($message);
		$from_username = $message->getFrom()->getUsername();
		$from_id = $message->getFrom()->getId();
		$chat_id = $message->getChat()->getId();
		$urlStart = Bot::getUrlStart();
		
		if ($from_username == '') {
			$group_data = Settings::getNew(['chat_id' => $chat_id]);
			if ($group_data[0]['enable_warn_username'] == 1 && !in_array($from_id, WARN_USERNAME_WHITELISTS)) {
				$limit = $group_data[0]['warning_username_limit'];
				$chat = 'Hey, Kamu di Mute sebentar 5 menit, Segera <b>Pasang username</b> ya.' .
					"\nJika sudah pasang Username, klik tombol <b>Sudah pasang</b> agar segera di UnMute.";
				$btn_markup = [
					['text' => 'Pasang Username', 'url' => $urlStart . 'username'],
					['text' => 'Sudah pasang', 'callback_data' => 'check_' . $from_id],
				];
				$chatHandler->restrictMember($from_id, '0:0:5');
				$chatHandler->deleteMessage($group_data[0]['last_warning_username_message_id']);
				$r = $chatHandler->sendText($chat, null, $btn_markup);
				
				Settings::saveNew([
					'last_warning_username_message_id' => $chatHandler->getSendedMessageId(),
					'chat_id'                          => $chat_id,
				], [
					'chat_id' => $chat_id,
				]);
				return $r;
			}
			$isNoUsername = true;
		}
		return $isNoUsername;
	}
	
	/**
	 * @return ServerResponse
	 * @throws TelegramException
	 */
	private function checkForwardedMessage()
	{
		$message = $this->getMessage();
		$res = null;
//		$msgValidator = new MessageValidator($message);
//		$res = $msgValidator->checkForwardedMessage();

//		$forwarded = $message->getForwardFrom();
		if ($message->getForwardFromMessageId() != "") {
//			$forwd_chat_id = $message->getForwardFromChat()->getId();
			$text = "Forwarded from forwd_chat_id";
			$res = $this->chatHandler->sendText($text);
		}
		
		return $res;
	}
	
	private function parseTags()
	{
		$message = $this->getMessage();
		$chatid = $message->getChat()->getId();
		$mssg_id = $message->getMessageId();
		$chatHandler = new ChatHandler($message);
		$repMssg = $message->getReplyToMessage();
		$pecah = Words::multiexplode([' ', "\n"], $message->getText());
		$limit = 1;
		foreach ($pecah as $pecahan) {
			if (($limit <= 3) && Words::isContain($pecahan, '#')) {
				$pecahan = ltrim($pecahan, '#');
				$hashtag = Words::isContain($pecahan, '#');
				if (!$hashtag && strlen($pecahan) >= 3) {
					$tag = Tags::getTag([
						'id_chat' => $chatid,
						'tag'     => $pecahan,
					]);
					
					if ($repMssg != null) {
						$mssg_id = $repMssg->getMessageId();
					}
					
					$id_data = $tag[0]['id_data'];
					$tipe_data = $tag[0]['type_data'];
					$btn_data = $tag[0]['btn_data']; // teks1|link1.com, teks2|link2.com
					
					$text = '#️⃣<code>#' . $tag[0]['tag'] . '</code>' .
						"\n" . $tag[0]['content'];
					
					$btns = [];
					if ($btn_data != null) {
//							if ($pecah[1] != '-r') {
						$abtn_data = explode(',', $btn_data); // teks1|link1.com teks2|link2.com
						foreach ($abtn_data as $btn) {
							$abtn = explode('|', trim($btn));
							$btns[] = [
								'text' => trim($abtn[0]),
								'url'  => trim($abtn[1]),
							];
						}
//
//							} else {
//								$text .= "\n" . $btn_data;
//							}
					}
					
					if ($tipe_data == 'text') {
						$data['text'] = $text;
						$r = $chatHandler->sendText($text, $mssg_id, $btns);
					} else {
						$data = [
							'chat_id'                  => $chatid,
							'parse_mode'               => 'HTML',
							'reply_to_message_id'      => $mssg_id,
							'disable_web_page_preview' => true,
							$tipe_data                 => $id_data,
							'caption'                  => $text,
						];
						
						switch ($tipe_data) {
							case 'document':
								$r = Request::sendDocument($data);
								break;
							case 'video':
								$r = Request::sendVideo($data);
								break;
							case 'voice':
								$r = Request::sendVoice($data);
								break;
							case 'photo':
								$r = Request::sendPhoto($data);
								break;
							case 'sticker':
								$r = Request::sendSticker($data);
								break;
						}
					}
					$limit++;
				}
			}
//			else{
//				$chatHandler->sendText("Due performance reason, we limit 3 batch call tags");
//				break;
//			}
		}
		return $r;
	}
}

